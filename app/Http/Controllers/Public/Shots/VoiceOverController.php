<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Jobs\GenerateVoiceOver;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VoiceOverController
{
    /**
     * Write the voice-over again, for example after the length changed.
     */
    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->chosenStoryline() === null) {
            throw ValidationException::withMessages(['voiceOver' => __('Choose a storyline first.')]);
        }

        $shot->forceFill(['voice_over' => null])->save();

        GenerateVoiceOver::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Speak every language of the voice-over again, for example after the text changed.
     */
    public function audio(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);

        if (blank($shot->voice_over)) {
            throw ValidationException::withMessages(['voiceOver' => __('Write the voice-over first.')]);
        }

        if ($project->settings->enabledLocales() === []) {
            throw ValidationException::withMessages(['voiceOver' => __('Choose the voice-over languages on the project page first.')]);
        }

        $shot->startVoiceOverAudio();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
