<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Style;

use App\Enums\StyleOptionStatus;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\StyleOptionResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\StyleOption;
use App\Support\Style\PinStyleOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PinController
{
    public function __construct(
        private readonly PinStyleOption $pinStyleOption,
    ) {}

    public function store(Project $project, StyleOption $styleOption): JsonResponse
    {
        Gate::authorize(ProjectPolicy::STYLE, $project);

        abort_unless($styleOption->status === StyleOptionStatus::READY, 422, __('This style has not been rendered yet.'));

        $styleOption->setRelation('project', $project);

        $project = $this->pinStyleOption->pin($styleOption);

        return response()->json([
            'option' => StyleOptionResource::make($styleOption->fresh()->load('media')->setRelation('project', $project)),
            'project' => ProjectResource::make($project->fresh()),
        ]);
    }
}
