<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Http\Requests\Public\ShotRequest;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotResource;
use App\Models\Policies\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UpdateController
{
    public function update(Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        $shot->setRelation('project', $project);

        return Inertia::render('shots/update', [
            'project' => fn() => ProjectResource::make($project),
            'shot' => fn() => ShotResource::make($shot),
            /** @var array<int, array{value: string, label: string}> */
            'purposes' => fn() => ProjectPurpose::collect()->map(fn(ProjectPurpose $purpose) => [
                'value' => $purpose->value,
                'label' => $purpose->description(),
            ])->all(),
            /** @var array<int, array{value: string, label: string}> */
            'aspectRatios' => fn() => AspectRatio::collect()->map(fn(AspectRatio $ratio) => [
                'value' => $ratio->value,
                'label' => $ratio->description(),
            ])->all(),
        ]);
    }

    public function store(ShotRequest $request, Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        if (! $shot->exists) {
            $shot->project_id = $project->id;
            $shot->position = ($project->shots()->max('position') ?? 0) + 1;
        }

        $shot->fill($request->shotAttributes());
        $shot->save();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function authorize(Project $project, Shot $shot): void
    {
        if ($shot->exists) {
            Gate::authorize(ShotPolicy::UPDATE, $shot);

            return;
        }

        Gate::authorize(ShotPolicy::CREATE, [Shot::class, $project]);
    }
}
