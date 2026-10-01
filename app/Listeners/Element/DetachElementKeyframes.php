<?php

declare(strict_types=1);

namespace App\Listeners\Element;

use App\Events\ElementDeleting;

/**
 * Unlinks an element from the keyframes it appeared in before it is removed.
 */
class DetachElementKeyframes
{
    public function handle(ElementDeleting $event): void
    {
        $event->element->keyframes()->detach();
    }
}
