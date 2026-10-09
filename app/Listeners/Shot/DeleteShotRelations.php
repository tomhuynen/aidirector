<?php

declare(strict_types=1);

namespace App\Listeners\Shot;

use App\Events\ShotDeleting;
use App\Models\Keyframe;
use App\Models\Shot;

/**
 * When a shot is deleted for good, removes its keyframes as models, so their
 * renders leave the disk too. The shot's own video goes with the shot's media.
 * A merged shot takes its parts with it; unmerge first to keep them. A shot
 * that is only deleted keeps everything, so what the system can learn from it
 * stays: its conversation, plan, keyframes and images.
 *
 * The AI calls, the corrections and the verdicts on the reviewers stay even
 * then: they are the cost history and what the system learns from.
 */
class DeleteShotRelations
{
    public function handle(ShotDeleting $event): void
    {
        if (! $event->shot->isForceDeleting()) {
            return;
        }

        $event->shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->delete());
        $event->shot->parts()->withTrashed()->get()->each(fn(Shot $part) => $part->forceDelete());
    }
}
