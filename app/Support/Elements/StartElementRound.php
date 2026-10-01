<?php

declare(strict_types=1);

namespace App\Support\Elements;

use App\Enums\ElementRoundStatus;
use App\Enums\ElementType;
use App\Jobs\GenerateElementSuggestions;
use App\Models\ElementRound;
use App\Models\Project;

/**
 * Starts, prepares or skips a cast and sets category for the intake chat.
 *
 * Rounds can be prepared in the background from a first brief, so their
 * grids are ready by the time the chat reaches the category. A prepared
 * round is presented as is when the brief still holds, and replaced when
 * the director's answer changed it.
 */
class StartElementRound
{
    /**
     * Suggestions for the category (pipeline.element_suggestions_count),
     * written and rendered in the background and shown in the chat now.
     * Replaces a round that was prepared for the category.
     */
    public function start(Project $project, ElementType $type, string $brief): ElementRound
    {
        $this->discardPrepared($project, $type);

        return $this->create($project, $type, $brief, presented: true);
    }

    /**
     * Prepares the category in the background without showing it. Does
     * nothing when the category is settled or already has a round.
     */
    public function prepare(Project $project, ElementType $type, string $brief): ?ElementRound
    {
        if ($project->elementRounds()->where('type', $type)->exists()) {
            return null;
        }

        return $this->create($project, $type, $brief, presented: false);
    }

    /**
     * Shows the round prepared for the category, when there is one that did not fail.
     */
    public function present(Project $project, ElementType $type): ?ElementRound
    {
        $round = $project->elementRounds()->where('type', $type)->get()
            ->first(fn(ElementRound $round) => $round->isPrepared() && $round->status !== ElementRoundStatus::FAILED);

        $round?->forceFill(['presented_at' => now()])->save();

        return $round;
    }

    /**
     * Settles the category without (more) elements: a round waiting in the
     * chat is closed, a prepared one discarded. Adds a skipped round when the
     * category was not settled yet.
     */
    public function skip(Project $project, ElementType $type): void
    {
        $this->discardPrepared($project, $type);

        $project->elementRounds()->where('type', $type)->get()
            ->filter(fn(ElementRound $round) => $round->isOpen())
            ->each(fn(ElementRound $round) => $round->forceFill(['status' => ElementRoundStatus::SKIPPED])->save());

        if (in_array($type, $project->settledElementTypes(), true)) {
            return;
        }

        $project->elementRounds()->create([
            'type' => $type,
            'status' => ElementRoundStatus::SKIPPED,
            'presented_at' => now(),
        ]);
    }

    private function create(Project $project, ElementType $type, string $brief, bool $presented): ElementRound
    {
        $round = $project->elementRounds()->create([
            'type' => $type,
            'brief' => $brief,
            'status' => ElementRoundStatus::SUGGESTING,
            'presented_at' => $presented ? now() : null,
        ]);

        GenerateElementSuggestions::dispatch($round);

        return $round;
    }

    /**
     * Deleted through the model, so the listener removes the suggestions and their renders.
     */
    private function discardPrepared(Project $project, ElementType $type): void
    {
        $project->elementRounds()->where('type', $type)->get()
            ->filter(fn(ElementRound $round) => $round->isPrepared())
            ->each(fn(ElementRound $round) => $round->delete());
    }
}
