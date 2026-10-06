<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Left for checks queued before {@see PollVideos} took over: it hands the
 * video to the shared round. Remove once no such jobs are left.
 */
#[DeleteWhenMissingModels]
class PollVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
        public readonly int $submittedAt,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        if ($this->shot->video_job_id === $this->jobId && $this->shot->video_submitted_at === null) {
            $this->shot->forceFill(['video_submitted_at' => now()->setTimestamp($this->submittedAt)])->save();
        }

        PollVideos::start();
    }
}
