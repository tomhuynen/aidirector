<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Resources\Public\ProjectResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\Decisions\DecisionQueue;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The project's decision queue: one decision at a time, oldest first.
 */
class DecisionsController
{
    public function view(Project $project, DecisionQueue $queue)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        return Inertia::render('projects/decisions', [
            'project' => fn() => ProjectResource::make($project),
            /** @var array<int, array<string, mixed>> */
            'decisions' => fn() => $queue->for($project)->all(),
        ]);
    }
}
