<?php

declare(strict_types=1);

namespace App\Support\Elements;

use App\Enums\ElementRoundStatus;
use App\Enums\ElementType;
use App\Jobs\GenerateElementSuggestions;
use App\Models\ElementRound;
use App\Models\Project;

/**
 * Starts or skips a cast and sets category for the intake chat.
 */
class StartElementRound
{
    /**
     * Suggestions for the category (pipeline.element_suggestions_count), written and rendered in the background.
     */
    public function start(Project $project, ElementType $type, string $brief): ElementRound
    {
        $round = $project->elementRounds()->create([
            'type' => $type,
            'brief' => $brief,
            'status' => ElementRoundStatus::SUGGESTING,
        ]);

        GenerateElementSuggestions::dispatch($round);

        return $round;
    }

    /**
     * Marks the category as settled without elements. Does nothing when it is settled already.
     */
    public function skip(Project $project, ElementType $type): void
    {
        if (in_array($type, $project->settledElementTypes(), true)) {
            return;
        }

        $project->elementRounds()->create([
            'type' => $type,
            'status' => ElementRoundStatus::SKIPPED,
        ]);
    }
}
