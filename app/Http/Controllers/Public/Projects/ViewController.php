<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    public function view(Project $project)
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        return Inertia::render('projects/view', [
            'project' => fn() => ProjectResource::make($project->loadCount('shots')),
            'shots' => fn() => ShotResource::collection($project->shots()->with('project')->get()),
        ]);
    }
}
