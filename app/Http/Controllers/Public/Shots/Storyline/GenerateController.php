<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Storyline;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\StorylineRequest;
use App\Jobs\GenerateStoryline;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GenerateController
{
    use GuardsBusyShots;

    /**
     * Plan the keyframes for the chosen storyline again, optionally with an instruction.
     */
    public function store(StorylineRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureShotIdle($shot, 'storyline');

        if ($shot->chosenStoryline() === null) {
            throw ValidationException::withMessages([
                'storyline' => __('Choose a storyline first.'),
            ]);
        }

        $shot->forceFill([
            'status' => ShotStatus::STORYLINE_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateStoryline::dispatch($shot, $request->validated('instruction'), draw: false);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
