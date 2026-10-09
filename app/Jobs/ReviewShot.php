<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Decisions\FindingsReport;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Reviews all keyframes of a finished shot together again after one of them
 * changed, so the notes match what the director sees now. Quick changes in a
 * row queue one review; issues the director already resolved stay resolved.
 * The shot is marked as reviewing meanwhile, so the editor can say so.
 */
#[DeleteWhenMissingModels]
class ReviewShot implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** The statuses in which every keyframe is drawn and the shot was reviewed before. */
    private const REVIEWED = [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_PENDING, ShotStatus::VIDEO_READY];

    /** How often the review waits for keyframe checks that are still running, about three minutes in all. */
    private const MAX_WAITS = 12;

    public function __construct(
        public readonly Shot $shot,
        /** How often it waited for keyframe checks already. */
        public readonly int $waited = 0,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    /**
     * Queue a review when the shot's keyframes are all drawn; while they are still being drawn the full render reviews them at the end.
     */
    public static function after(Shot $shot): void
    {
        if (Config::get('pipeline.keyframe_check') && in_array($shot->status, self::REVIEWED, true)) {
            $shot->forceFill(['reviewing' => true])->save();
            self::dispatch($shot);
        }
    }

    public function uniqueId(): string
    {
        return 'review-shot-' . (Tenant::current()?->getKey() ?? 'landlord') . '-' . $this->shot->getKey();
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');
        $keyframes = $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot);

        // The keyframe checks run after drawing; the review waits for them, so their findings are reported with it.
        if ($this->waited < self::MAX_WAITS && $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->render_stage === Keyframe::STAGE_CHECKING)) {
            self::dispatch($shot, $this->waited + 1)->delay(now()->addSeconds(15));

            return;
        }

        $review = $painter->review($shot, $keyframes);

        if ($review === null || $this->planReplaced()) {
            $shot->forceFill(['reviewing' => false])->save();

            return;
        }

        // What was resolved is read as stored now: the director may have fixed or dismissed issues while the review ran.
        $shot->updateStoredJson('keyframe_review', function (?array $stored) use ($review) {
            $resolved = $stored['resolved'] ?? null;

            return $resolved === null ? $review : [...$review, 'resolved' => $resolved];
        });

        // What the checks and the review found is told in the chat, before the editor stops showing the review as running.
        app(FindingsReport::class)->report($shot->fresh() ?? $shot);

        $shot->forceFill(['reviewing' => false])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->forceFill(['reviewing' => false])->save();
    }
}
