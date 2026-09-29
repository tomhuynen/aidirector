<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Http\Requests\Public\ProjectRequest;
use App\Http\Resources\Public\ProjectResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UpdateController
{
    public function update(Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        return Inertia::render('projects/update', [
            'project' => fn() => ProjectResource::make($project),
            /** @var array<int, array{value: string, label: string, summary: string}> */
            'purposes' => fn() => ProjectPurpose::collect()->map(fn(ProjectPurpose $purpose) => [
                'value' => $purpose->value,
                'label' => $purpose->description(),
                'summary' => $purpose->summary(),
            ])->all(),
            /** @var array<int, array{value: string, label: string}> */
            'aspectRatios' => fn() => AspectRatio::collect()->map(fn(AspectRatio $ratio) => [
                'value' => $ratio->value,
                'label' => $ratio->description(),
            ])->all(),
        ]);
    }

    public function store(ProjectRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        if (! $project->exists) {
            $project->director_id = $request->user('director')->id;
        }

        $project->fill($request->projectAttributes());
        $project->save();

        return redirect()->route('public.projects.view', $project);
    }
}
