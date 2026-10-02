<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Storyline;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\StorylineChoiceRequest;
use App\Jobs\GenerateStoryline;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;

class ChooseController
{
    /**
     * Save the chosen storyline, name the shot after it and start planning its keyframes.
     */
    public function store(StorylineChoiceRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $chosen = $shot->storylineOptions()[$request->integer('option')];

        $shot->forceFill([
            'title' => $chosen['title'],
            'chosen_storyline' => $chosen,
            'storyline' => null,
            'storyline_error' => null,
            'status' => ShotStatus::STORYLINE_PENDING,
        ])->save();

        GenerateStoryline::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Go back to the suggestions to pick a different storyline.
     */
    public function destroy(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->forgetKeyframes();

        $shot->forceFill([
            'chosen_storyline' => null,
            'storyline' => null,
            'storyline_error' => null,
            'status' => $shot->storylineOptions() === [] ? ShotStatus::DRAFT : ShotStatus::OPTIONS_READY,
        ])->save();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
