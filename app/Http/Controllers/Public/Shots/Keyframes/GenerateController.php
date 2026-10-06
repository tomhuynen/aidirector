<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Jobs\GenerateKeyframes;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GenerateController
{
    use GuardsBusyShots;
    use ReturnsToDecisions;

    /**
     * Render the images for the planned keyframes again, keeping the plan as it is.
     * It starts over at choosing the first keyframe.
     */
    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureShotIdle($shot, 'keyframes');

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

        return $this->afterAction($project, $shot);
    }
}
