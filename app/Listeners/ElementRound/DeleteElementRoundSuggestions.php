<?php

declare(strict_types=1);

namespace App\Listeners\ElementRound;

use App\Events\ElementRoundDeleting;
use App\Models\ElementSuggestion;

/**
 * Removes a round's suggestions as models, so their rendered images go too.
 */
class DeleteElementRoundSuggestions
{
    public function handle(ElementRoundDeleting $event): void
    {
        $event->round->suggestions()->get()->each(fn(ElementSuggestion $suggestion) => $suggestion->delete());
    }
}
