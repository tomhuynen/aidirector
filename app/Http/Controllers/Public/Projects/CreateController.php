<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\ProjectIntake;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A new project starts as a conversation with the director agent instead of
 * a form. Editing an existing project still uses the form (UpdateController).
 */
class CreateController
{
    public function view()
    {
        Gate::authorize(ProjectPolicy::CREATE, Project::class);

        return Inertia::render('projects/create', [
            /** @var string */
            'greeting' => ProjectIntake::greeting(),
            'chatUrl' => route('public.projects.chat'),
            'uploadUrl' => route('public.uploads.store'),
        ]);
    }
}
