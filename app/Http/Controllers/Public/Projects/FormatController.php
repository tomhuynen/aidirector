<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Requests\Public\ProjectFormatRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class FormatController
{
    /**
     * Set the project's video format: its aspect ratio and resolution.
     */
    public function store(ProjectFormatRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $project->forceFill([
            'aspect_ratio' => $request->validated('aspectRatio'),
            'video_resolution' => $request->validated('resolution'),
        ])->save();

        return redirect()->route('public.projects.view', $project);
    }
}
