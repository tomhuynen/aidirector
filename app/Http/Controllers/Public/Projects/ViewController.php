<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Resources\Public\ProjectResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    /**
     * The editor is the project's home. With shots, open the first one;
     * without shots, show the empty editor.
     */
    public function view(Project $project)
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        if ($first = $project->shots()->first()) {
            return redirect()->route('public.shots.view', [$project, $first]);
        }

        return Inertia::render('projects/view', [
            'project' => fn() => ProjectResource::make($project),
        ]);
    }
}
