<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Project;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\SilentVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Checks the cover loop job until it is done, then keeps the clip on the
 * project, unless the cover was drawn again in the meantime.
 */
#[DeleteWhenMissingModels]
class PollCoverLoop implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    private const RUNNING = ['pending', 'queued', 'in_progress', 'processing', 'running'];

    public function __construct(
        public readonly Project $project,
        public readonly string $jobId,
        public readonly int $coverId,
        public readonly int $submittedAt,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(OpenRouterVideoClient $videos): void
    {
        if ($this->project->getFirstMedia(Project::COVER)?->id !== $this->coverId) {
            return;
        }

        $status = $videos->status($this->jobId);
        $state = $status['status'];

        if (in_array($state, self::RUNNING, true)) {
            if (now()->getTimestamp() - $this->submittedAt > (int) Config::get('pipeline.video.max_wait_minutes') * 60) {
                throw new RuntimeException('The cover loop took too long to render.');
            }

            self::dispatch($this->project, $this->jobId, $this->coverId, $this->submittedAt)
                ->delay(now()->addSeconds((int) Config::get('pipeline.video.poll_seconds')));

            return;
        }

        if ($state !== 'completed') {
            throw new RuntimeException($status['error'] ?? "The cover loop job ended as {$state}.");
        }

        $this->project->addMediaFromString(app(SilentVideo::class)->strip($videos->download($this->jobId)))
            ->usingFileName('cover-loop.mp4')
            ->toMediaCollection(Project::COVER_LOOP);

        $this->project->generations()->create([
            'director_id' => $this->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.cover_loop'),
            'prompt' => GenerateCoverLoop::prompt(),
            'usage' => $status['usage'] ?? null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->project->generations()->create([
            'director_id' => $this->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.cover_loop'),
            'prompt' => GenerateCoverLoop::prompt(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
