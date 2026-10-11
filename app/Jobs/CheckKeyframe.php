<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Keyframe;
use App\Support\Findings\FindingsReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Checks a drawn keyframe after it is shown, without holding it up: whether
 * the people, the objects and their state and the point of the keyframe are
 * right. What it finds is kept on the render as notes; the plan director
 * reports them in the chat once the shot review is done, or right away while
 * keyframe 1 waits to be confirmed, since no review follows then. It never
 * redraws by itself.
 */
#[DeleteWhenMissingModels]
class CheckKeyframe implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $renderId,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    /**
     * Queue the check for a keyframe's render, when checks are on.
     */
    public static function start(Keyframe $keyframe, Media $render): void
    {
        if (Config::get('pipeline.keyframe_check')) {
            // Shown as checking in the editor, without blocking the keyframe for changes.
            $keyframe->forceFill(['render_stage' => Keyframe::STAGE_CHECKING])->save();
            self::dispatch($keyframe, (int) $render->id);
        }
    }

    public function handle(KeyframePainter $painter, FindingsReport $report): void
    {
        $shot = $this->keyframe->shot()->with('project')->firstOrFail();
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $keyframe = $siblings->firstWhere('id', $this->keyframe->id);
        $render = $keyframe?->renders()->firstWhere('id', $this->renderId);

        try {
            // The keyframe was drawn again meanwhile; that render gets its own check.
            if ($keyframe === null || $render === null || $keyframe->render_id !== $render->id) {
                return;
            }

            $issues = $painter->check($keyframe, $render, $siblings);

            if ($issues !== null) {
                $issues === []
                    ? $render->forgetCustomProperty(Keyframe::CHECK_ISSUES)->save()
                    : $render->setCustomProperty(Keyframe::CHECK_ISSUES, $issues)->save();
            }

            // Read as stored now: keyframe 1 may have been confirmed while the check ran.
            $fresh = $shot->fresh();

            if ($fresh?->status === ShotStatus::FIRST_KEYFRAME_READY) {
                $report->report($fresh);
            }
        } finally {
            $this->doneChecking();
        }
    }

    public function failed(): void
    {
        $this->doneChecking();
    }

    /**
     * Clear the checking label, unless the keyframe is being drawn again and shows its own progress.
     */
    private function doneChecking(): void
    {
        Keyframe::query()
            ->whereKey($this->keyframe->getKey())
            ->where('render_stage', Keyframe::STAGE_CHECKING)
            ->where('rendering', false)
            ->update(['render_stage' => null]);
    }
}
