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
use App\Notifications\Public\GenerationFinished;
use App\Support\Video\OpenRouterVideoClient;
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
 * as a reference to the video model before handing off to {@see PollVideos}.
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

    public function handle(OpenRouterVideoClient $videos, KeyframePainter $painter): void
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

        $duration = self::duration($shot);
        $prompt = VideoPrompt::compose(
            $this->writePrompt($shot, $keyframes, $duration, $painter),
            self::timeline($keyframes, $duration),
            $duration,
        );

        $references = $keyframes
            ->map(fn(Keyframe $keyframe) => 'data:' . $keyframe->render()->mime_type . ';base64,' . base64_encode($this->bytes($keyframe)))
            ->values()
            ->all();

        if ($this->planReplaced()) {
            return;
        }

        // No first frame: with one, Wan 3.0 animates from that image alone and leaves the references out, so the cast changes.
        $jobId = $videos->submit(
            Config::get('pipeline.models.video'),
            $prompt,
            $references,
            $duration,
            $shot->aspectRatio()->value,
            $shot->videoResolution(),
        );

        $shot->forceFill([
            'video_prompt' => $prompt,
            'video_job_id' => $jobId,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        // The spoken tracks are made while the video renders.
        $shot->startVoiceOverAudio();

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
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $this->shot->video_prompt,
            'error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be started. Please try again.'),
        ])->save();

        GenerationFinished::failed(__('The video of “:shot” could not be started', ['shot' => $this->shot->title]), route('public.shots.view', [$this->shot->project, $this->shot]))
            ->sendTo($this->shot->project);
    }

    /**
     * A montage gets one clip per still, each starting on its still; they are
     * joined with crossfades once all are rendered, by {@see PollShotClips}.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     */
    private function submitStills(Shot $shot, Collection $keyframes, OpenRouterVideoClient $videos): void
    {
        // Each clip lasts its share of the shot, rounded up to the whole seconds the model renders; the join cuts it to its share.
        $duration = Shot::clampSeconds((int) ceil(PollShotClips::clipLength($shot->durationInSeconds(), $keyframes->count())));
        $model = (string) Config::get('pipeline.models.video');

        $clips = $keyframes->values()->map(function (Keyframe $keyframe) use ($shot, $videos, $duration, $model): array {
            $still = 'data:' . $keyframe->render()->mime_type . ';base64,' . base64_encode($this->bytes($keyframe));

            return [
                'position' => $keyframe->position,
                'job_id' => $videos->submit($model, VideoPrompt::still($keyframe->fullDescription(), $duration), [], $duration, $shot->aspectRatio()->value, $shot->videoResolution(), ['first_frame' => $still]),
                'status' => 'pending',
            ];
        })->all();

        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);

        $shot->forceFill([
            'video_prompt' => VideoPrompt::still('(the description of each still)', $duration),
            'video_job_id' => null,
            'montage_clips' => $clips,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        $shot->startVoiceOverAudio();

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
            'video_job_id' => null,
            'montage_clips' => $clips,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        PollShotClips::dispatch($shot)->delay((int) Config::get('pipeline.video.first_poll_seconds'));
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
     * When each keyframe is reached, spread evenly over the clip like the editor shows them.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{position: int, title: string, seconds: float}>
     */
    public static function timeline(Collection $keyframes, int $duration): array
    {
        $steps = max($keyframes->count() - 1, 1);

        return $keyframes->values()
            ->map(fn(Keyframe $keyframe, int $index) => [
                'position' => $keyframe->position,
                'title' => $keyframe->title,
                'seconds' => round($duration * $index / $steps, 1),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{style: string, action: string, details: string}
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
        ];
    }

    private function bytes(Keyframe $keyframe): string
    {
        $render = $keyframe->render();

        return (string) Storage::disk($render->disk)->get($render->getPathRelativeToRoot());
    }
}
