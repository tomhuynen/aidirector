<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Http\Requests\Public\PlanVoiceOverRequest;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The director's answer to a voice-over the plan director suggested in the
 * chat: accepted, it becomes the shot's voice-over, and the shot becomes
 * long enough to speak it; declined, the one there is stays.
 */
class VoiceOverController
{
    public function store(PlanVoiceOverRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $turns = array_values((array) ($shot->plan_chat ?? []));
        $index = $request->integer('turn');
        $suggested = $turns[$index]['voiceOver'] ?? null;

        if (! is_array($suggested) || isset($suggested['state'])) {
            throw ValidationException::withMessages(['turn' => __('This voice-over was already answered.')]);
        }

        $accepted = $request->boolean('accept');
        $turns[$index]['voiceOver']['state'] = $accepted ? 'accepted' : 'declined';

        $shot->forceFill([
            'plan_chat' => $turns,
            ...($accepted ? ['voice_over' => (string) $suggested['text']] : []),
            ...($accepted && isset($suggested['seconds']) ? ['duration' => (int) $suggested['seconds']] : []),
        ])->save();

        return response()->json(['messages' => $turns]);
    }
}
