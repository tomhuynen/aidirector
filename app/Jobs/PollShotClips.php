<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Shot;
use App\Support\Video\ClipJoiner;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\SilentVideo;
use App\Support\Video\VideoJobs;
use App\Support\Video\VideoTrimmer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Follows the clips of a montage, one per still on Luma, or of a presenter
 * shot, one per language on OpenRouter. A montage's clips are cut to their
 * share of the shot length and joined with crossfades into the shot's video
 * once all are in; a presenter's clips keep their sound and are stored per
 * language, the first one also as the shot's video. While clips still
 * render, the job queues itself for the next round.
 */
#[DeleteWhenMissingModels]
class PollShotClips implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    private const RUNNING = ['pending', 'queued', 'dreaming', 'in_progress', 'processing', 'running'];

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function uniqueId(): string
    {
        return 'poll-clips-' . (Tenant::current()?->getKey() ?? 'landlord') . '-' . $this->shot->getKey();
    }

    public function handle(LumaVideoClient $luma, OpenRouterVideoClient $openRouter, ClipJoiner $joiner): void
    {
        $shot = $this->shot->load(['project', 'media']);
        $clips = (array) ($shot->montage_clips ?? []);

        if ($shot->status !== ShotStatus::VIDEO_PENDING || $clips === []) {
            return;
        }

        $videos = $this->provider($shot, $luma, $openRouter);

        $pending = array_values(array_filter($clips, fn(array $clip) => $clip['status'] === 'pending'));
        $statuses = $videos->statuses(array_column($pending, 'job_id'));
        $waited = now()->getTimestamp() - (int) $shot->video_submitted_at?->getTimestamp();

        foreach ($clips as $index => $clip) {
            $status = $clip['status'] === 'pending' ? ($statuses[(string) $clip['job_id']] ?? null) : null;

            if ($status === null) {
                continue;
            }

            if (in_array($status['status'], self::RUNNING, true)) {
                if ($waited > (int) Config::get('pipeline.video.max_wait_minutes') * 60) {
                    $this->fail($shot, 'A clip took too long to render.');

                    return;
                }

                continue;
            }

            if ($status['status'] !== 'completed') {
                $this->fail($shot, $status['error'] ?? 'A clip ended as ' . $status['status'] . '.');

                return;
            }

            $bytes = $videos->download((string) $clip['job_id']);

            // A presenter speaks: the clip keeps its sound.
            $shot->addMediaFromString($shot->isPresenter() ? $bytes : app(SilentVideo::class)->strip($bytes))
                ->usingFileName(isset($clip['locale']) ? "presenter-{$shot->position}-{$clip['locale']}.mp4" : "still-{$clip['position']}.mp4")
                ->withCustomProperties(isset($clip['locale']) ? ['locale' => $clip['locale']] : ['position' => $clip['position']])
                ->toMediaCollection(Shot::MONTAGE_CLIPS);

            $clips[$index] = [...$clip, 'status' => 'done', 'cost' => $this->cost($shot, $clip, $status)];
        }

        $shot->forceFill(['montage_clips' => $clips])->save();

        if (collect($clips)->contains('status', 'pending')) {
            self::dispatch($shot)->delay((int) Config::get('pipeline.video.poll_seconds'));

            return;
        }

        $shot->isPresenter()
            ? $this->storeLanguages($shot->load('media'), $clips)
            : $this->join($shot->load('media'), $clips, $joiner);
    }

    /**
     * Who renders the clips of this shot: Luma a montage's stills, OpenRouter a presenter's languages.
     */
    private function provider(Shot $shot, LumaVideoClient $luma, OpenRouterVideoClient $openRouter): VideoJobs
    {
        return $shot->isPresenter() ? $openRouter : $luma;
    }

    /**
     * What a finished clip cost: as OpenRouter reports it for a presenter, from the price list for a Luma clip of a still.
     *
     * @param  array{seconds?: int}  $clip
     * @param  array{usage?: array<string, mixed>|null}  $status
     */
    private function cost(Shot $shot, array $clip, array $status): ?float
    {
        if ($shot->isPresenter()) {
            return is_numeric($status['usage']['cost'] ?? null) ? (float) $status['usage']['cost'] : null;
        }

        return isset($clip['seconds']) ? LumaVideoClient::cost((int) $clip['seconds'], $shot->videoResolution()) : null;
    }

    /**
     * Keeps the presenter's clip of every language, the first one also as the shot's video.
     *
     * @param  list<array{locale: string, job_id: string, status: string, cost?: float|null}>  $clips
     */
    private function storeLanguages(Shot $shot, array $clips): void
    {
        $shot->clearMediaCollection(Shot::PRESENTER_VIDEOS);
        $media = $shot->getMedia(Shot::MONTAGE_CLIPS)->keyBy(fn(Media $clip) => (string) $clip->getCustomProperty('locale'));

        foreach ($clips as $index => $clip) {
            $video = $media->get($clip['locale']);

            if ($video === null) {
                continue;
            }

            if ($index === 0) {
                $video->copy($shot, Shot::VIDEO, $video->disk, "shot-{$shot->position}.mp4");
            }

            $video->move($shot, Shot::PRESENTER_VIDEOS);
        }

        $this->finish($shot, $clips);
    }

    /**
     * Cuts each clip to its share of the shot length and joins them with crossfades into the shot's video.
     *
     * @param  list<array{position: int, job_id: string, seconds: int, status: string, cost?: float|null}>  $clips
     */
    private function join(Shot $shot, array $clips, ClipJoiner $joiner): void
    {
        $media = $shot->getMedia(Shot::MONTAGE_CLIPS)->sortBy(fn(Media $clip) => (int) $clip->getCustomProperty('position'))->values();
        $directory = storage_path('tmp/montage-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $paths = $media->map(function (Media $clip, int $index) use ($directory): string {
                $path = "{$directory}/still-{$index}.mp4";
                File::put($path, (string) Storage::disk($clip->disk)->get($clip->getPathRelativeToRoot()));

                return $path;
            })->all();

            $output = "{$directory}/montage.mp4";
            $lengths = self::clipLengths($shot->keyframeSeconds());

            // One still: its clip is cut to how long it shows, as the join would cut it.
            if (count($paths) > 1) {
                $timeline = $joiner->join($paths, ShotTransition::CROSSFADE, $output, $lengths);
            } else {
                app(VideoTrimmer::class)->trim($paths[0], $output, 0.0, $lengths[0]);
                $timeline = ['starts' => [0.0], 'duration' => $lengths[0]];
            }

            $shot->addMedia($output)
                ->usingFileName("shot-{$shot->position}.mp4")
                ->withCustomProperties([Shot::PART_STARTS => $timeline['starts'], Shot::VIDEO_SECONDS => $timeline['duration']])
                ->toMediaCollection(Shot::VIDEO);
        } finally {
            File::deleteDirectory($directory);
        }

        $this->finish($shot, $clips);
    }

    /**
     * Logs the render, clears the clips and tells the director the video is ready.
     *
     * @param  list<array<string, mixed>>  $clips
     */
    private function finish(Shot $shot, array $clips): void
    {
        $costs = array_filter(array_column($clips, 'cost'), fn(mixed $cost) => $cost !== null);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => $shot->isPresenter() ? 'openrouter' : GenerateVideo::PROVIDER,
            'model' => Config::get($shot->isPresenter() ? 'pipeline.models.presenter' : 'pipeline.models.video'),
            'prompt' => $shot->video_prompt,
            'cost' => $costs === [] ? null : array_sum($costs),
            'duration_ms' => $shot->video_submitted_at === null ? null : (int) $shot->video_submitted_at->diffInMilliseconds(now()),
        ]);

        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'montage_clips' => null,
            'video_error' => null,
        ])->save();
    }

    /**
     * How long the clip of each still is cut to: the seconds it shows plus its
     * share of the crossfades, so together, overlapping, they last as long as
     * the stills.
     *
     * @param  list<float>  $seconds  how long each still shows, in order
     * @return list<float>
     */
    public static function clipLengths(array $seconds): array
    {
        $count = count($seconds);
        $overlap = ClipJoiner::FADE_SECONDS * max($count - 1, 0) / max($count, 1);

        return array_map(fn(float $shows) => round(max(1.5, $shows + $overlap), 2), $seconds);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        $this->fail($this->shot, (string) $exception?->getMessage());
    }

    private function fail(Shot $shot, string $error): void
    {
        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);
        $shot->forceFill(['montage_clips' => null])->save();

        DownloadVideo::markFailed($shot, $error);
    }
}
