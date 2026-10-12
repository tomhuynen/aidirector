<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ShotStatus;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\FirstKeyframeChoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starting a shot over from the menu, as the director could also ask in the
 * chat: from the places with the same plan, or from the plan itself. The
 * conversation notes it, so the plan director knows where it stands.
 */
class RestartController
{
    /**
     * Another place for the same plan: back to the places while keyframe 1
     * waits, or new places once the keyframes are drawn; those go.
     */
    public function place(Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! self::canPickPlace($shot)) {
            throw ValidationException::withMessages(['restart' => __('Another place can be picked once the plan is drawn and nothing is being drawn.')]);
        }

        if ($shot->status === ShotStatus::FIRST_KEYFRAME_READY && $shot->hasChosenPlate()) {
            $choice->anotherPlace($shot);
            $shot->say(__('Back to the places. Click the one you like, or ask me for more places.'));
        } else {
            $shot->startPlanOver();
            $shot->drawKeyframes();
            $shot->say(__('Starting over from the places with the same plan; the keyframes that were drawn are gone. New places are being drawn.'));
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * A new plan with the same takeaway: the drawn keyframes, the places and
     * the planned keyframes go, and the conversation asks for the idea again.
     */
    public function plan(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! self::canStartOver($shot)) {
            throw ValidationException::withMessages(['restart' => __('The shot can start over once nothing is being drawn.')]);
        }

        $shot->startPlanOver();
        $shot->updateStoredJson('storyline', fn(?array $storyline) => array_diff_key($storyline ?? [], array_flip(['keyframes', 'framing', 'place_edits', 'setting_from', 'new_elements', 'left_behind', 'draw_when_ready'])));
        $shot->forceFill(['chosen_storyline' => null])->save();
        $shot->updateStoredJson('plan_chat', fn(?array $chat) => [...array_values($chat ?? []), [
            'role' => 'assistant',
            'text' => __('Starting over with the same takeaway: the plan and the drawn keyframes are gone. What should happen in this shot?'),
            'stage' => 'idea',
        ]]);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Once a plan is drawn from, keyframe 1 or more, and nothing is being drawn.
     */
    public static function canPickPlace(Shot $shot): bool
    {
        return self::canStartOver($shot)
            && ($shot->hasChosenPlate() || $shot->keyframes()->whereNotNull('render_id')->exists());
    }

    /**
     * Once there is a plan, and nothing is being drawn.
     */
    public static function canStartOver(Shot $shot): bool
    {
        return $shot->storylineKeyframes() !== [] && ! $shot->status->isWorking() && ! $shot->keyframes()->where('rendering', true)->exists();
    }
}
