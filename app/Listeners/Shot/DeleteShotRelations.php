<?php

declare(strict_types=1);

namespace App\Listeners\Shot;

use App\Events\ShotDeleting;
use App\Models\Keyframe;
use App\Models\Shot;

/**
 * Removes a shot's keyframes as models, so their renders leave the disk too.
 * The shot's own video goes with the shot's media. A merged shot takes its
 * parts with it; unmerge first to keep them.
 */
class DeleteShotRelations
{
    public function handle(ShotDeleting $event): void
    {
        $event->shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->delete());
        $event->shot->parts()->get()->each(fn(Shot $part) => $part->delete());
    }
}
