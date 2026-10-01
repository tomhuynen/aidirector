<?php

declare(strict_types=1);

namespace App\Listeners\Shot;

use App\Events\ShotDeleting;
use App\Models\Keyframe;

/**
 * Removes a shot's keyframes as models, so their renders leave the disk too.
 * The shot's own video goes with the shot's media.
 */
class DeleteShotRelations
{
    public function handle(ShotDeleting $event): void
    {
        $event->shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->delete());
    }
}
