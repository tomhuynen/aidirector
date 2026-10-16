<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\VideoPromptWriter;
use App\Ai\Agents\VoiceOverTranslator;
use App\Ai\KeyframePainter;
use App\Ai\Prompts\VideoPrompt;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\StillVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Turns the shot's keyframes into a video request: has the text model write
 * the shot-specific prompt and submits it with every keyframe image, in order,
 * to the video model before handing off to {@see PollVideos}. On OpenRouter the
 * keyframes are references; on Luma each is pinned to the frame it is reached on.
 * An insert's clip is made from its still in code straight away.
 */
#[DeleteWhenMissingModels]
class GenerateVideo implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function handle(OpenRouterVideoClient $videos, KeyframePainter $painter, StillVideo $stills): void
    {
        $shot = $this->shot->load(['project', 'keyframes.media']);
        $keyframes = $shot->keyframes;

        if ($keyframes->isEmpty() || $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->render() === null)) {
            throw new RuntimeException('Every keyframe needs an image before the video can be rendered.');
        }

        if ($shot->isMontage()) {
            $this->submitStills($shot, $keyframes, $videos);

            return;
        }

        if ($shot->isPresenter()) {
            $this->submitPresenter($shot, $keyframes->first(), $videos);

            return;
        }

        if ($shot->isInsert()) {
            $this->makeFromStill($shot, $keyframes->first(), $stills);

            return;
        }

        $duration = self::duration($shot);
        $parts = $this->writePrompt($shot, $keyframes, $duration, $painter);
        // A scene is paced by the video model itself; a close-up the director timed keeps its times.
        $prompt = VideoPrompt::compose(
            $parts,
            $keyframes->count(),
            $duration,
            $shot->kindOrScene()->timedByHand() ? self::timeline($shot, $keyframes) : [],
        );

        if ($this->planReplaced()) {
            return;
        }

        $jobId = self::rendersOnLuma()
            ? $this->submitToLuma($shot, $keyframes, $prompt, app(LumaVideoClient::class))
            : $this->submitToOpenRouter($shot, $keyframes, $prompt, $duration, $videos);

        $shot->forceFill([
            'video_prompt' => $prompt,
            'video_summary' => $parts['summary'],
            'video_job_id' => $jobId,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        // The spoken tracks are made while the video renders.
        if (Config::boolean('pipeline.voice_over.automatic')) {
            $shot->startVoiceOverAudio();
        }

        PollVideos::start();
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'video',
            'provider' => self::provider(),
            'model' => self::model(),
            'prompt' => $this->shot->video_prompt,
            'error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be started. Please try again.'),
        ])->save();
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     */
    private function submitToOpenRouter(Shot $shot, Collection $keyframes, string $prompt, int $duration, OpenRouterVideoClient $videos): string
    {
        $references = $keyframes
            ->map(fn(Keyframe $keyframe) => 'data:' . $keyframe->render()->mime_type . ';base64,' . base64_encode($this->bytes($keyframe)))
            ->values()
            ->all();

        // No first frame: with one, Wan 3.0 animates from that image alone and leaves the references out, so the cast changes.
        return $videos->submit((string) Config::get('pipeline.models.video'), $prompt, $references, $duration, $shot->aspectRatio()->value, $shot->videoResolution());
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     */
    private function submitToLuma(Shot $shot, Collection $keyframes, string $prompt, LumaVideoClient $luma): string
    {
        $sorted = $keyframes->sortBy('position')->values();
        $pins = self::lumaPins($shot, $sorted);

        return $luma->submit(
            self::model(),
            $prompt,
            array_map(fn(array $pin) => ['bytes' => $this->bytes($sorted[$pin['keyframe']]), 'mime' => (string) $sorted[$pin['keyframe']]->render()->mime_type], $pins['pins']),
            array_column($pins['pins'], 'frame'),
            $pins['seconds'],
            $shot->aspectRatio()->value,
            $shot->videoResolution(),
        );
    }

    /**
     * Where each keyframe is pinned in a Luma clip: on the frame it is
     * reached, and the last one also on the frame the shot ends, so it is
     * held until then. The clip is the shortest length Ray renders that
     * holds the shot; a longer shot is pinned into it in proportion.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{seconds: int, pins: list<array{keyframe: int, frame: int}>}
     */
    public static function lumaPins(Shot $shot, Collection $keyframes): array
    {
        $duration = self::duration($shot);
        $clip = LumaVideoClient::lengthFor($duration);
        $lastFrame = $clip * LumaVideoClient::FPS;
        $scale = min(1.0, $clip / $duration) * LumaVideoClient::FPS;
        $pins = [];
        $frame = -1;
        $start = 0.0;

        foreach ($shot->keyframeSeconds($keyframes) as $index => $seconds) {
            $frame = min($lastFrame, max($frame + 1, (int) round($start * $scale)));
            $pins[] = ['keyframe' => $index, 'frame' => $frame];
            $start += $seconds;
        }

        $end = min($lastFrame, (int) round($duration * $scale));

        if ($pins !== [] && $end > $frame) {
            $pins[] = ['keyframe' => $pins[count($pins) - 1]['keyframe'], 'frame' => $end];
        }

        return ['seconds' => $clip, 'pins' => $pins];
    }

    /**
     * Whether scenes and close-ups render on Luma instead of OpenRouter.
     */
    public static function rendersOnLuma(): bool
    {
        return Config::get('pipeline.video.provider') === 'luma';
    }

    public static function provider(): string
    {
        return self::rendersOnLuma() ? 'luma' : 'openrouter';
    }

    /**
     * The model scenes and close-ups render with.
     */
    public static function model(): string
    {
        return (string) Config::get(self::rendersOnLuma() ? 'pipeline.models.luma_video' : 'pipeline.models.video');
    }

    /**
     * A montage gets one clip per still, each starting on its still; they are
     * joined with crossfades once all are rendered, by {@see PollShotClips}.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     */
    private function submitStills(Shot $shot, Collection $keyframes, OpenRouterVideoClient $videos): void
    {
        // Each clip lasts as long as its still shows, rounded up to the whole seconds the model renders; the join cuts it to that.
        $lengths = PollShotClips::clipLengths($shot->keyframeSeconds($keyframes));
        $model = (string) Config::get('pipeline.models.video');

        $clips = $keyframes->sortBy('position')->values()->map(function (Keyframe $keyframe, int $index) use ($shot, $videos, $lengths, $model): array {
            $still = 'data:' . $keyframe->render()->mime_type . ';base64,' . base64_encode($this->bytes($keyframe));
            $duration = Shot::clampSeconds((int) ceil($lengths[$index]));

            return [
                'position' => $keyframe->position,
                'job_id' => $videos->submit($model, VideoPrompt::still($keyframe->fullDescription(), $duration), [], $duration, $shot->aspectRatio()->value, $shot->videoResolution(), ['first_frame' => $still]),
                'status' => 'pending',
            ];
        })->all();

        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);

        $shot->forceFill([
            'video_prompt' => VideoPrompt::still('(the description of each still)', Shot::clampSeconds((int) ceil(max($lengths)))),
            'video_summary' => $keyframes->sortBy('position')->map(fn(Keyframe $keyframe) => rtrim($keyframe->staged()->summary ?: $keyframe->title, '.') . '.')->join(' '),
            'video_job_id' => null,
            'montage_clips' => $clips,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        if (Config::boolean('pipeline.voice_over.automatic')) {
            $shot->startVoiceOverAudio();
        }

        PollShotClips::dispatch($shot)->delay((int) Config::get('pipeline.video.first_poll_seconds'));
    }

    /**
     * A presenter shot gets one lip-synced clip per language of the project,
     * each with its own sound; {@see PollShotClips} stores them once rendered.
     */
    private function submitPresenter(Shot $shot, Keyframe $still, OpenRouterVideoClient $videos): void
    {
        if (blank($shot->voice_over)) {
            throw new RuntimeException('A presenter needs a voice-over to speak.');
        }

        $image = 'data:' . $still->render()->mime_type . ';base64,' . base64_encode($this->bytes($still));
        $model = (string) Config::get('pipeline.models.presenter');
        // The presenter keeps one voice per language across shots; it is chosen the first time they speak.
        $presenter = $still->elements()->get()->first(fn(Element $element) => $element->type === ElementType::PERSON);

        if ($presenter !== null) {
            JudgeElementVoice::judge($presenter);
        }

        $voices = (array) Config::get('pipeline.presenter.voices.male');
        // Without languages switched on, the voice-over is spoken as written.
        $locales = $shot->project->settings->enabledLocales() ?: ['en-GB'];

        $clips = array_map(function (string $locale) use ($shot, $videos, $image, $model, $voices, $presenter): array {
            $voice = $presenter?->voiceIdFor($locale) ?? (string) ($voices[strtolower((string) strtok($locale, '-'))] ?? $voices['*']);

            return [
                'locale' => $locale,
                'job_id' => $videos->submitPresenter($model, $this->presenterText($shot, $locale), $image, $voice, $shot->aspectRatio()->value, (string) Config::get('pipeline.presenter.resolution')),
                'status' => 'pending',
            ];
        }, $locales);

        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);

        $shot->forceFill([
            'video_prompt' => $shot->voice_over,
            'video_summary' => $shot->voice_over,
            'video_job_id' => null,
            'montage_clips' => $clips,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        PollShotClips::dispatch($shot)->delay((int) Config::get('pipeline.video.first_poll_seconds'));
    }

    private function makeFromStill(Shot $shot, Keyframe $still, StillVideo $stills): void
    {
        $started = hrtime(true);
        $prompt = __('A slow push-in on the still, made in code.');
        $seconds = $shot->keyframeSeconds(collect([$still]))[0];
        $clip = $stills->make($this->bytes($still), $seconds, $shot->aspectRatio()->value, $shot->videoResolution());

        if ($this->planReplaced()) {
            return;
        }

        $shot->addMediaFromString($clip)->usingFileName("shot-{$shot->position}.mp4")->withCustomProperties([Shot::VIDEO_SECONDS => $seconds])->toMediaCollection(Shot::VIDEO);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'ffmpeg',
            'model' => 'ffmpeg',
            'prompt' => $prompt,
            'cost' => 0,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_prompt' => $prompt,
            'video_summary' => trim(rtrim($still->staged()->summary ?: $still->title, '.') . '. ' . __('The camera pushes in slowly.')),
            'video_job_id' => null,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        if (Config::boolean('pipeline.voice_over.automatic')) {
            $shot->startVoiceOverAudio();
        }
    }

    /**
     * What the presenter says in one language: the translation of its spoken
     * track when that is up to date, the voice-over itself in English, and
     * otherwise a fresh translation.
     */
    private function presenterText(Shot $shot, string $locale): string
    {
        $script = (string) $shot->voice_over;
        $track = $shot->getMedia(Shot::VOICE_OVERS)->first(fn($media) => $media->getCustomProperty('locale') === $locale && $media->getCustomProperty('script') === $script);

        if ($track !== null) {
            return (string) $track->getCustomProperty('text');
        }

        if (str_starts_with($locale, 'en-')) {
            return $script;
        }

        $translator = new VoiceOverTranslator($locale, $shot->durationInSeconds());
        $model = (string) Config::get('pipeline.models.text');

        /** @var StructuredAgentResponse $response */
        $response = $translator->prompt($translator->promptFor($script), provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $translator->promptFor($script),
            'usage' => $response->usage->toArray(),
        ]);

        $translated = trim((string) ($response->toArray()['text'] ?? ''));

        return $translated !== '' ? $translated : $script;
    }

    /**
     * The clip length: the shot's duration within what the video model accepts.
     */
    public static function duration(Shot $shot): int
    {
        return Shot::clampSeconds($shot->durationInSeconds());
    }

    /**
     * When each keyframe is reached: every keyframe lasts its own seconds
     * until the next one, as the editor shows them.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{position: int, title: string, seconds: float}>
     */
    public static function timeline(Shot $shot, Collection $keyframes): array
    {
        $seconds = $shot->keyframeSeconds($keyframes);
        $starts = array_map(fn(int $index) => round(array_sum(array_slice($seconds, 0, $index)), 1), array_keys($seconds));

        return $keyframes->sortBy('position')->values()
            ->map(fn(Keyframe $keyframe, int $index) => [
                'position' => $keyframe->position,
                'title' => $keyframe->title,
                'seconds' => $starts[$index],
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{style: string, action: string, details: string, summary: string}
     */
    private function writePrompt(Shot $shot, Collection $keyframes, int $duration, KeyframePainter $painter): array
    {
        $writer = new VideoPromptWriter($shot);
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        // The writer sees the keyframes, so it describes the cast as drawn instead of as their role suggests.
        $images = $keyframes->map(fn(Keyframe $keyframe) => $painter->referenceFor($keyframe->render()))->values()->all();

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt($writer->promptFor($keyframes, $duration), attachments: $images, provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        return [
            'style' => (string) $response['style'],
            'action' => (string) $response['action'],
            'details' => (string) $response['details'],
            'summary' => trim((string) ($response['summary'] ?? '')),
        ];
    }

    private function bytes(Keyframe $keyframe): string
    {
        $render = $keyframe->render();

        return (string) Storage::disk($render->disk)->get($render->getPathRelativeToRoot());
    }
}
