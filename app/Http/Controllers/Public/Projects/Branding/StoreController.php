<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Branding;

use App\Http\Requests\Public\BrandingRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Upload;
use App\Support\Media\ClaimUploads;
use Illuminate\Support\Facades\Gate;

class StoreController
{
    /**
     * Add logos to the project's branding, each named by its file name. They
     * are the only text an image may show, and only drawn from these pictures.
     */
    public function store(BrandingRequest $request, Project $project, ClaimUploads $claimUploads)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $claimUploads->toCollection($project, Upload::query()->whereSqidIn('id', $request->validated('logos'))->get(), Project::LOGOS);

        return back();
    }
}
