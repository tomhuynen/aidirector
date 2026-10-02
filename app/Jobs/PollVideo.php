<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\SilentVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Checks a submitted video job. While it is still running it schedules
 * itself again; once done it downloads the clip onto the shot and logs the
 * generation with its usage.
 */
#[DeleteWhenMissingModels]
class PollVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    private const RUNNING = ['pending', 'queued', 'in_progress', 'processing', 'running'];

    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
        public readonly int $submittedAt,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(OpenRouterVideoClient $videos): void
    {
        $shot = $this->shot->load('project');

        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        $status = $videos->status($this->jobId);
        $state = $status['status'];

        if (in_array($state, self::RUNNING, true)) {
            if (now()->getTimestamp() - $this->submittedAt > (int) Config::get('pipeline.video.max_wait_minutes') * 60) {
                throw new RuntimeException('The video took too long to render.');
            }

            self::dispatch($shot, $this->jobId, $this->submittedAt)
                ->delay(now()->addSeconds((int) Config::get('pipeline.video.poll_seconds')));

            return;
        }

        if ($state !== 'completed') {
            throw new RuntimeException($status['error'] ?? "The video job ended as {$state}.");
        }

        $shot->addMediaFromString(app(SilentVideo::class)->strip($videos->download($this->jobId)))
            ->usingFileName("shot-{$shot->position}.mp4")
            ->toMediaCollection(Shot::VIDEO);

        $usage = $status['usage'] ?? [];

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $shot->video_prompt,
            'cost' => is_numeric($usage['cost'] ?? null) ? $usage['cost'] : null,
            'duration_ms' => (now()->getTimestamp() - $this->submittedAt) * 1000,
            'usage' => $usage ?: null,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => null,
        ])->save();

        GenerationFinished::ready(__('The video of “:shot” is ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
            ->sendTo($shot->project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $this->shot->video_prompt,
            'duration_ms' => (now()->getTimestamp() - $this->submittedAt) * 1000,
            'error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be rendered. Please try again.'),
        ])->save();

        GenerationFinished::failed(__('The video of “:shot” could not be rendered', ['shot' => $this->shot->title]), route('public.shots.view', [$this->shot->project, $this->shot]))
            ->sendTo($this->shot->project);
    }
}
