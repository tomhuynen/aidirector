<?php

declare(strict_types=1);

namespace App\Listeners\Keyframe;

use App\Events\KeyframeDeleting;

/**
 * Unlinks a keyframe from the cast and sets; the elements themselves stay in the project.
 */
class DetachKeyframeElements
{
    public function handle(KeyframeDeleting $event): void
    {
        $event->keyframe->elements()->detach();
    }
}
