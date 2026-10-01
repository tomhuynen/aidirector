<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Where the cast and sets group picture stands, polled by the intake chat
 * so it opens the project page only once the header is drawn.
 */
class CoverController
{
    public function show(Project $project): JsonResponse
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        return response()->json([
            /** @var 'painting'|'ready'|'failed'|null */
            'status' => $project->cover_status?->value,
        ]);
    }
}
