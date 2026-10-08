<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Director;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The director's projects, newest first, for another application to pick from.
 */
class ProjectController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Director $director */
        $director = $request->user();

        return ProjectResource::collection(
            Project::query()->where('director_id', $director->id)->withCount('shots')->latest('updated_at')->get(),
        );
    }
}
