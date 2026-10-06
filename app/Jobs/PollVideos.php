<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Support\Video\OpenRouterVideoClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Checks every video that is rendering in the tenant in one round, the
 * status requests sent in parallel. A finished clip is handed to
 * {@see DownloadVideo}; a failed or timed-out one is marked failed. While
 * any video still renders, the job queues itself for the next round. Only
 * one round is ever queued per tenant.
 */
class PollVideos implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    private const RUNNING = ['pending', 'queued', 'in_progress', 'processing', 'running'];

    public function __construct()
    {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    /**
     * Queue a round for when the next video is due to be checked, unless one is queued already.
     */
    public static function start(): void
    {
        $next = self::pending()->min(fn(Shot $shot) => self::dueAt($shot));

        if ($next !== null) {
            self::dispatch()->delay(max(1, $next - now()->getTimestamp()));
        }
    }

    public function uniqueId(): string
    {
        return 'poll-videos-' . (Tenant::current()?->getKey() ?? 'landlord');
    }

    public function handle(OpenRouterVideoClient $videos): void
    {
        $due = self::pending()->filter(fn(Shot $shot) => self::dueAt($shot) <= now()->getTimestamp());
        $statuses = $videos->statuses($due->pluck('video_job_id')->all());
        $maxWait = (int) Config::get('pipeline.video.max_wait_minutes') * 60;

        foreach ($due as $shot) {
            $status = $statuses[(string) $shot->video_job_id] ?? null;
            $waited = now()->getTimestamp() - (int) $shot->video_submitted_at?->getTimestamp();

            // Not readable this round: asked again in the next one.
            if ($status === null) {
                continue;
            }

            if (in_array($status['status'], self::RUNNING, true)) {
                if ($waited > $maxWait) {
                    DownloadVideo::markFailed($shot, 'The video took too long to render.');
                }

                continue;
            }

            if ($status['status'] !== 'completed') {
                DownloadVideo::markFailed($shot, $status['error'] ?? "The video job ended as {$status['status']}.");

                continue;
            }

            if (Cache::add("video-download:{$shot->video_job_id}", true, now()->addMinutes(15))) {
                DownloadVideo::dispatch($shot, (string) $shot->video_job_id, $status['usage'] ?? []);
            }
        }

        $this->queueNextRound();
    }

    private function queueNextRound(): void
    {
        $waiting = self::pending()->reject(fn(Shot $shot) => Cache::has("video-download:{$shot->video_job_id}"));

        if ($waiting->isEmpty()) {
            return;
        }

        $next = max($waiting->min(fn(Shot $shot) => self::dueAt($shot)), now()->getTimestamp() + (int) Config::get('pipeline.video.poll_seconds'));

        self::dispatch()->delay($next - now()->getTimestamp());
    }

    /**
     * @return Collection<int, Shot>
     */
    private static function pending(): Collection
    {
        return Shot::query()
            ->where('status', ShotStatus::VIDEO_PENDING)
            ->whereNotNull('video_job_id')
            ->get();
    }

    /**
     * When a video is first worth checking: a while after it was submitted.
     */
    private static function dueAt(Shot $shot): int
    {
        return (int) $shot->video_submitted_at?->getTimestamp() + (int) Config::get('pipeline.video.first_poll_seconds');
    }
}
