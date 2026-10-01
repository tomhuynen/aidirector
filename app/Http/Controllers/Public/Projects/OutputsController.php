<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Requests\Public\ProjectOutputsRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class OutputsController
{
    /**
     * Save the video outputs the project should deliver.
     */
    public function store(ProjectOutputsRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $outputs = collect($request->validated('outputs'))
            ->map(fn(array $output) => ['aspect_ratio' => $output['aspectRatio'], 'resolution' => $output['resolution']])
            ->unique(fn(array $output) => "{$output['aspect_ratio']} {$output['resolution']}")
            ->values()
            ->all();

        $project->forceFill(['video_outputs' => $outputs])->save();

        return redirect()->route('public.projects.view', $project);
    }
}
