<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Shots\MergeShots;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\SilentVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Downloads a finished clip onto its shot, without sound, logs the
 * generation with its usage and tells the director. A part of a merged
 * shot joins the merged video again.
 */
#[DeleteWhenMissingModels]
class DownloadVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    /**
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
        public readonly array $usage = [],
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(OpenRouterVideoClient $videos): void
    {
        $shot = $this->shot->load('project');

        // A newer render was started in the meantime.
        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        $shot->addMediaFromString(app(SilentVideo::class)->strip($videos->download($this->jobId)))
            ->usingFileName("shot-{$shot->position}.mp4")
            ->toMediaCollection(Shot::VIDEO);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $shot->video_prompt,
            'cost' => is_numeric($this->usage['cost'] ?? null) ? $this->usage['cost'] : null,
            'duration_ms' => self::waitedMs($shot),
            'usage' => $this->usage ?: null,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => null,
        ])->save();

        GenerationFinished::ready(__('The video of “:shot” is ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
            ->sendTo($shot->project);

        $merged = $shot->mergedInto()->first();

        if ($merged !== null) {
            app(MergeShots::class)->rejoin($merged);
        }
    }

    public function failed(?Throwable $exception): void
    {
        self::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * Log the failed render and give the shot back to the director to try again.
     */
    public static function markFailed(Shot $shot, string $error): void
    {
        $shot->loadMissing('project');

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $shot->video_prompt,
            'duration_ms' => self::waitedMs($shot),
            'error' => $error,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be rendered. Please try again.'),
        ])->save();

        GenerationFinished::failed(__('The video of “:shot” could not be rendered', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
            ->sendTo($shot->project);
    }

    private static function waitedMs(Shot $shot): ?int
    {
        return $shot->video_submitted_at === null ? null : (int) $shot->video_submitted_at->diffInMilliseconds(now());
    }
}
