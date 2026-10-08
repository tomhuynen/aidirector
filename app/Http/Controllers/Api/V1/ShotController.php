<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ShotAssetsResource;
use App\Http\Resources\Api\V1\ShotResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A project's shots in story order, and the files of one shot.
 */
class ShotController
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        return ShotResource::collection(
            $project->shots()->with('media')->get()->each->setRelation('project', $project),
        );
    }

    public function assets(Project $project, Shot $shot): ShotAssetsResource
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        return ShotAssetsResource::make($shot->load(['media', 'keyframes.media', 'parts.keyframes.media']));
    }
}
