<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    public function destroy(Project $project)
    {
        Gate::authorize(ProjectPolicy::DESTROY, $project);

        $project->delete();

        return redirect()->route('public.projects.index');
    }
}
