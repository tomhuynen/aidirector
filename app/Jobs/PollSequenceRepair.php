<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Support\Video\ClipSplicer;
use App\Support\Video\LumaVideoClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Waits for a part rendered again by {@see RepairSequenceShot}; once it is
 * done, the new piece replaces that part of the sequence clip, which keeps
 * its length and where each part starts.
 */
#[DeleteWhenMissingModels]
class PollSequenceRepair implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const array RUNNING = ['pending', 'queued', 'dreaming', 'in_progress', 'processing', 'running'];

    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
        public readonly float $start,
        public readonly float $end,
        public readonly string $prompt,
        public readonly int $clipSeconds,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(LumaVideoClient $luma, ClipSplicer $splicer): void
    {
        $shot = $this->shot->load(['project', 'media']);

        // Another render or repair was started in the meantime.
        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        $status = $luma->statuses([$this->jobId])[$this->jobId] ?? null;
        $waited = now()->getTimestamp() - (int) $shot->video_submitted_at?->getTimestamp();

        if ($status === null || in_array($status['status'], self::RUNNING, true)) {
            if ($waited > (int) Config::get('pipeline.video.max_wait_minutes') * 60) {
                self::markFailed($shot, 'The repair took too long to render.');

                return;
            }

            self::dispatch($shot, $this->jobId, $this->start, $this->end, $this->prompt, $this->clipSeconds)->delay((int) Config::get('pipeline.video.poll_seconds'));

            return;
        }

        if ($status['status'] !== 'completed') {
            self::markFailed($shot, $status['error'] ?? "The repair ended as {$status['status']}.");

            return;
        }

        $video = $shot->video();
        $directory = storage_path('tmp/repair-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/clip.mp4", (string) Storage::disk($video->disk)->get($video->getPathRelativeToRoot()));
            File::put("{$directory}/piece.mp4", $luma->download($this->jobId));
            $splicer->splice("{$directory}/clip.mp4", "{$directory}/piece.mp4", $this->start, $this->end, "{$directory}/repaired.mp4");

            $shot->addMedia("{$directory}/repaired.mp4")
                ->usingFileName($video->file_name)
                ->withCustomProperties($video->custom_properties)
                ->toMediaCollection(Shot::VIDEO);
        } finally {
            File::deleteDirectory($directory);
        }

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => GenerateVideo::PROVIDER,
            'model' => GenerateVideo::model(),
            'prompt' => $this->prompt,
            'cost' => LumaVideoClient::cost($this->clipSeconds, $shot->videoResolution() === '1080p' ? '1080p' : '720p'),
            'duration_ms' => $waited * 1000,
        ]);

        $shot->forceFill(['status' => ShotStatus::VIDEO_READY, 'video_error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        self::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * Logs the failed repair; the sequence keeps the clip it had.
     */
    public static function markFailed(Shot $shot, string $error): void
    {
        $shot->loadMissing('project');

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => GenerateVideo::PROVIDER,
            'model' => GenerateVideo::model(),
            'error' => $error,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => __('The part could not be rendered again. Please try again.'),
        ])->save();
    }
}
