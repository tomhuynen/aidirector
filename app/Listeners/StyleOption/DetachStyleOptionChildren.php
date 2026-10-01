<?php

declare(strict_types=1);

namespace App\Listeners\StyleOption;

use App\Events\StyleOptionDeleting;

/**
 * Options branched from this one keep their place in the exploration but
 * lose the link to a parent that no longer exists.
 */
class DetachStyleOptionChildren
{
    public function handle(StyleOptionDeleting $event): void
    {
        $event->option->children()->update(['parent_id' => null]);
    }
}
