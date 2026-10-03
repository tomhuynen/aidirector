<?php

declare(strict_types=1);

namespace App\Listeners\Project;

use App\Events\ProjectDeleting;
use App\Models\Element;
use App\Models\ElementRound;
use App\Models\Shot;
use App\Models\StyleOption;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Removes everything a project owns before the project itself goes. Each
 * child is deleted as a model, so its own listeners and media cleanup run
 * and no rendered image, sheet or video is left on disk. The usage log
 * (generations) is kept as cost history.
 */
class DeleteProjectRelations
{
    public function handle(ProjectDeleting $event): void
    {
        $project = $event->project;

        $project->allShots()->get()->each(fn(Shot $shot) => $shot->delete());

        $project->elements()->get()->each(fn(Element $element) => $element->delete());

        $project->elementRounds()->get()->each(fn(ElementRound $round) => $round->delete());

        $project->corrections()->delete();
        $project->rules()->delete();

        // Children first, so a parent never outlives the options that point at it.
        $project->styleOptions()->reorder()->orderByDesc('round')->get()->each(fn(StyleOption $option) => $option->delete());

        if ($project->conversation_id !== null) {
            ConversationMessage::query()->where('conversation_id', $project->conversation_id)->delete();
            Conversation::query()->whereKey($project->conversation_id)->delete();
        }
    }
}
