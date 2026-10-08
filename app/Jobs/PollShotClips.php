<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Shots\MergeShots;
use App\Support\Video\ClipJoiner;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\SilentVideo;
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
 * Follows the clips of a montage, one per still, or of a presenter shot, one
 * per language. A montage's clips are cut to their share of the shot length
 * and joined with crossfades into the shot's video once all are in; a
 * presenter's clips keep their sound and are stored per language, the first
 * one also as the shot's video. While clips still render, the job queues
 * itself for the next round.
 */
#[DeleteWhenMissingModels]
class PollShotClips implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    private const RUNNING = ['pending', 'queued', 'in_progress', 'processing', 'running'];

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

    public function handle(OpenRouterVideoClient $videos, ClipJoiner $joiner): void
    {
        $shot = $this->shot->load(['project', 'media']);
        $clips = (array) ($shot->montage_clips ?? []);

        if ($shot->status !== ShotStatus::VIDEO_PENDING || $clips === []) {
            return;
        }

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

            $clips[$index] = [...$clip, 'status' => 'done', 'cost' => is_numeric($status['usage']['cost'] ?? null) ? (float) $status['usage']['cost'] : null];
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
     * @param  list<array{position: int, job_id: string, status: string, cost?: float|null}>  $clips
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
            $length = self::clipLength($shot->durationInSeconds(), count($paths));
            $timeline = count($paths) > 1
                ? $joiner->join($paths, ShotTransition::CROSSFADE, $output, array_fill(0, count($paths), $length))
                : ['starts' => [0.0], 'duration' => (float) $length];

            $shot->addMedia(count($paths) > 1 ? $output : $paths[0])
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
            'provider' => 'openrouter',
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

        GenerationFinished::ready(__('The video of “:shot” is ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
            ->sendTo($shot->project);

        $merged = $shot->mergedInto()->first();

        if ($merged !== null) {
            app(MergeShots::class)->rejoin($merged);
        }
    }

    /**
     * How long each still shows: together, with the crossfades overlapping, they fill the shot length.
     */
    public static function clipLength(int $seconds, int $count): float
    {
        $overlap = ClipJoiner::FADE_SECONDS * max($count - 1, 0);

        return round(max(1.5, ($seconds + $overlap) / max($count, 1)), 2);
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
