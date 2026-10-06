<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Requests\Public\ProjectVoiceOverRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class VoiceOverController
{
    /**
     * Turn the voice-over on or off and choose its languages.
     */
    public function store(ProjectVoiceOverRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $project->settings = $project->settings->withVoiceOver(
            $request->boolean('enabled'),
            array_values($request->array('locales')),
        );
        $project->save();

        return back();
    }
}
