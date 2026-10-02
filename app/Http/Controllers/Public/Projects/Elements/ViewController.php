<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Enums\ElementType;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\ProjectResource;
use App\Models\Element;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The page of one cast or set element: its picture beside a form to name,
 * describe and change it. Without an element it creates one of a type.
 */
class ViewController
{
    public function create(Request $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $type = ElementType::from($request->validate(['type' => ['required', Rule::enum(ElementType::class)]])['type']);

        return Inertia::render('elements/edit', [
            'project' => fn() => ProjectResource::make($project),
            'element' => null,
            /** @var array{value: string, label: string, plural: string} */
            'type' => fn() => ['value' => $type->value, 'label' => $type->description(), 'plural' => $type->plural()],
            /** @var string */
            'saveUrl' => fn() => route('public.projects.elements.store', $project),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
            /** The cast and sets that can be drawn into the new element. */
            'elements' => fn() => ElementResource::collection(
                $project->elements()->with('media')->get()->each->setRelation('project', $project)
            ),
        ]);
    }

    public function view(Project $project, Element $element)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $element->setRelation('project', $project)->load('media');

        return Inertia::render('elements/edit', [
            'project' => fn() => ProjectResource::make($project),
            'element' => fn() => ElementResource::make($element),
            /** @var array{value: string, label: string, plural: string} */
            'type' => fn() => ['value' => $element->type->value, 'label' => $element->type->description(), 'plural' => $element->type->plural()],
            /** @var string */
            'saveUrl' => fn() => route('public.projects.elements.update', [$project, $element]),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
            /** The other cast and sets that can be drawn into it. */
            'elements' => fn() => ElementResource::collection(
                $project->elements()->whereKeyNot($element->getKey())->with('media')->get()->each->setRelation('project', $project)
            ),
        ]);
    }
}
