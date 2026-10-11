<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Chooses a place of a sequence by clicking its picture in the chat of the
 * sequence's first shot, the same as saying which one in the chat.
 */
class SequencePlaceController
{
    public function choose(Request $request, Project $project, Shot $shot, SequencePlaces $places)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $request->validate(['place' => ['required', 'integer']]);

        $waiting = $places->waiting($shot);
        $option = $waiting['options']->firstWhere('id', $request->integer('place'))
            ?? throw ValidationException::withMessages(['place' => __('That place cannot be chosen now.')]);
        $number = $waiting['options']->search(fn($media) => $media->is($option)) + 1;

        $shot->updateStoredJson('plan_chat', fn(?array $chat) => [...array_values($chat ?? []), ['role' => 'director', 'text' => __('Place :n for :place.', ['n' => $number, 'place' => $waiting['place']['name']])]]);
        $chosen = $places->choose($shot, $option, say: false);
        // The chosen place stays in the conversation, with what comes next.
        $shot->updateStoredJson('plan_chat', fn(?array $chat) => [...array_values($chat ?? []), ['role' => 'assistant', 'text' => $chosen['text'], 'chosen_place' => $chosen['place']]]);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
