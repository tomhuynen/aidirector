<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Http\Resources\Public\ProjectResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IndexController
{
    public function index(Request $request)
    {
        Gate::authorize(ProjectPolicy::INDEX, Project::class);

        return Inertia::render('projects/index', [
            'projects' => fn() => ProjectResource::collection(
                Project::query()
                    ->whereBelongsTo($request->user('director'))
                    ->whereNull('archived_at')
                    ->with('media')
                    ->withCount('shots')
                    ->latest('updated_at')
                    ->get()
            ),
        ]);
    }
}
