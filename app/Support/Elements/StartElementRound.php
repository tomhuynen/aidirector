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
 *
 * Prepared rounds are written one at a time, in category order: the next
 * waits until the one before it has queued its renders, so people render
 * before places and places before objects.
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

        $round = $this->create($project, $type, $brief);
        $this->dispatchNextWaiting($project);

        return $round;
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

        $round = $project->elementRounds()->create([
            'type' => $type,
            'brief' => $brief,
            'status' => ElementRoundStatus::WAITING,
        ]);

        $this->dispatchNextWaiting($project);

        return $round->refresh();
    }

    /**
     * Shows the round prepared for the category, when there is one that did
     * not fail. One still waiting its turn is written now: the chat is there.
     */
    public function present(Project $project, ElementType $type): ?ElementRound
    {
        $round = $project->elementRounds()->where('type', $type)->get()
            ->first(fn(ElementRound $round) => $round->isPrepared() && $round->status !== ElementRoundStatus::FAILED);

        if ($round === null) {
            return null;
        }

        $round->forceFill(['presented_at' => now()])->save();

        if ($round->status === ElementRoundStatus::WAITING) {
            $this->write($round);
        }

        return $round;
    }

    /**
     * Starts writing the first prepared round that waits its turn, once no
     * other round of the project is being written. Called again whenever a
     * round finished writing, failed, or was discarded.
     */
    public function dispatchNextWaiting(Project $project): void
    {
        $rounds = $project->elementRounds()->get();

        if ($rounds->contains(fn(ElementRound $round) => $round->status === ElementRoundStatus::SUGGESTING)) {
            return;
        }

        $next = $rounds->filter(fn(ElementRound $round) => $round->status === ElementRoundStatus::WAITING)
            ->sortBy(fn(ElementRound $round) => array_search($round->type, ElementType::cases(), true))
            ->first();

        if ($next !== null) {
            $this->write($next);
        }
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

        if (! in_array($type, $project->settledElementTypes(), true)) {
            $project->elementRounds()->create([
                'type' => $type,
                'status' => ElementRoundStatus::SKIPPED,
                'presented_at' => now(),
            ]);
        }

        $this->dispatchNextWaiting($project);
    }

    private function create(Project $project, ElementType $type, string $brief): ElementRound
    {
        $round = $project->elementRounds()->create([
            'type' => $type,
            'brief' => $brief,
            'status' => ElementRoundStatus::SUGGESTING,
            'presented_at' => now(),
        ]);

        GenerateElementSuggestions::dispatch($round);

        return $round;
    }

    private function write(ElementRound $round): void
    {
        $round->forceFill(['status' => ElementRoundStatus::SUGGESTING])->save();

        GenerateElementSuggestions::dispatch($round);
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
