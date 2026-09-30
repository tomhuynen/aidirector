<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Storyline;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\StorylineFeedbackRequest;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;

class SuggestController
{
    /**
     * Suggest storylines for the shot, or a new set based on the director's feedback.
     */
    public function store(StorylineFeedbackRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->forceFill([
            'status' => ShotStatus::OPTIONS_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateStorylineOptions::dispatch($shot, $request->validated('feedback'));

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
