<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GenerateController
{
    /**
     * Render the images for the planned keyframes again, keeping the plan as it is.
     * It starts over at choosing the first keyframe.
     */
    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->storylineKeyframes() === []) {
            throw ValidationException::withMessages([
                'keyframes' => __('Plan the keyframes first.'),
            ]);
        }

        $shot->forceFill([
            'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateKeyframes::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
