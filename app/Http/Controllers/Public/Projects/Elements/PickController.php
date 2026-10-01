<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Enums\ElementRoundStatus;
use App\Enums\ElementSuggestionStatus;
use App\Http\Requests\Public\ElementPickRequest;
use App\Models\Element;
use App\Models\ElementRound;
use App\Models\ElementSuggestion;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\Elements\ElementRoundState;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns the suggestions the director ticked into cast and sets. Each new
 * element keeps the suggestion's render as its reference image, so nothing
 * is drawn twice; the suggestions that were not picked are deleted.
 */
class PickController
{
    public function store(ElementPickRequest $request, Project $project, ElementRound $elementRound, ElementRoundState $state): JsonResponse
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        if ($elementRound->status !== ElementRoundStatus::READY) {
            throw ValidationException::withMessages(['suggestions' => __('These suggestions cannot be picked from anymore.')]);
        }

        $ids = $request->suggestionIds();
        $suggestions = $elementRound->suggestions()->with('media')->get();
        $picked = $suggestions->filter(fn(ElementSuggestion $suggestion) => in_array($suggestion->sqid, $ids, true));

        if ($picked->isEmpty() || $picked->contains(fn(ElementSuggestion $suggestion) => $suggestion->status !== ElementSuggestionStatus::READY)) {
            throw ValidationException::withMessages(['suggestions' => __('Pick suggestions whose images are ready.')]);
        }

        $elements = DB::connection($project->getConnectionName())->transaction(function () use ($project, $elementRound, $suggestions, $picked) {
            $elements = $picked->map(function (ElementSuggestion $suggestion) use ($project, $elementRound): Element {
                $element = $project->elements()->create([
                    'type' => $elementRound->type,
                    'name' => $suggestion->name,
                    'description' => $suggestion->description,
                ]);

                $render = $suggestion->render();

                if ($render !== null) {
                    $element->addMediaFromDisk($render->getPathRelativeToRoot(), $render->disk)
                        ->preservingOriginal()
                        ->usingFileName('element-' . $element->sqid . '.' . pathinfo($render->file_name, PATHINFO_EXTENSION))
                        ->toMediaCollection(Element::REFERENCE);
                }

                $suggestion->forceFill(['picked_at' => now()])->save();

                return $element;
            })->values();

            $suggestions->reject(fn(ElementSuggestion $suggestion) => $picked->contains($suggestion))
                ->each(fn(ElementSuggestion $suggestion) => $suggestion->delete());

            $elementRound->forceFill(['status' => ElementRoundStatus::PICKED])->save();

            return $elements;
        });

        $elementRound->setRelation('project', $project);

        return response()->json([
            /** @var array{id: string, type: 'person'|'place'|'object', label: string, status: 'suggesting'|'ready'|'picked'|'skipped'|'failed', error: string|null, pollUrl: string, pickUrl: string, options: array<int, array{id: string, name: string, description: string, status: 'pending'|'ready'|'failed', picked: bool, fromPhoto: bool, thumbnailUrl: string|null, imageUrl: string|null}>} */
            'round' => $state->for($elementRound->refresh()->setRelation('project', $project)),
            /** @var array<int, string> */
            'names' => $elements->map(fn(Element $element) => (string) $element->name)->all(),
        ]);
    }
}
