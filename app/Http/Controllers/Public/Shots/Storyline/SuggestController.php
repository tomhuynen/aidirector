<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Storyline;

use App\Enums\CorrectionSource;
use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\StorylineFeedbackRequest;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use Illuminate\Support\Facades\Gate;

class SuggestController
{
    use GuardsBusyShots;

    /**
     * Suggest storylines for the shot, or a new set based on the director's feedback.
     */
    public function store(StorylineFeedbackRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureShotIdle($shot, 'feedback');

        $shot->forceFill([
            'status' => ShotStatus::OPTIONS_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateStorylineOptions::dispatch($shot, $request->validated('feedback'));

        if (filled($feedback = $request->validated('feedback'))) {
            $titles = collect($shot->storylineOptions())->pluck('title')->join(', ');
            RecordCorrection::record($project, CorrectionSource::FEEDBACK, (string) $feedback, $shot, context: "Takeaway of the shot: {$shot->takeaway}. Suggested storylines: {$titles}.");
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
