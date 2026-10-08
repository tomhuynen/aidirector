<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ElementType;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Jobs\UpdateElementImage;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The places and objects the planner found missing from the cast and sets:
 * adding them makes them cast and sets like any other, with their picture
 * drawn right away, so every keyframe that shows them draws them the same.
 */
class ElementsController
{
    use ReturnsToDecisions;

    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $proposed = (array) ($shot->storyline['new_elements'] ?? []);

        if ($proposed === []) {
            throw ValidationException::withMessages(['elements' => __('There is nothing to add to the cast and sets.')]);
        }

        $added = [];

        foreach ($proposed as $proposal) {
            // One with this name may have been added meanwhile, such as from another shot.
            $element = $project->elements()->where('name', $proposal['name'])->first() ?? $project->elements()->create([
                'type' => ElementType::from($proposal['type']),
                'name' => $proposal['name'],
                'description' => $proposal['description'],
                'rendering' => true,
            ]);

            if ($element->wasRecentlyCreated) {
                UpdateElementImage::dispatch($element);
            }

            $added[] = $element->sqid;
        }

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [
            ...array_diff_key($storyline ?? [], ['new_elements' => true]),
            'added_elements' => array_values(array_unique([...($storyline['added_elements'] ?? []), ...$added])),
        ]);

        return $this->afterAction($project, $shot);
    }

    /**
     * Leave them out: the plan describes them in words only.
     */
    public function destroy(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->updateStoredJson('storyline', fn(?array $storyline) => array_diff_key($storyline ?? [], ['new_elements' => true]));

        return $this->afterAction($project, $shot);
    }
}
