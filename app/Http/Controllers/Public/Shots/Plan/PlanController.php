<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The plan of a shot after its keyframes are drawn: back to the plan chat.
 */
class PlanController
{
    use ReturnsToDecisions;

    /**
     * Back to the plan chat from the drawn keyframes: they are thrown away
     * with the places and the video, the conversation goes on with what did
     * not work, and whatever is still drawn or rendered for the old plan stops.
     */
    public function reopen(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->storyline === null) {
            throw ValidationException::withMessages(['plan' => __('This shot has no plan to go back to.')]);
        }

        // First, so running jobs of the old plan stop at their next check.
        $shot->increment('plan_version');

        $shot->forgetKeyframes();
        $shot->clearMediaCollection(Shot::PLATE_OPTIONS);
        $shot->clearMediaCollection(Shot::PLATE);
        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);
        $shot->clearMediaCollection(Shot::PRESENTER_VIDEOS);

        $shot->forceFill([
            'status' => ShotStatus::STORYLINE_READY,
            'storyline_error' => null,
            'montage_clips' => null,
            'reviewing' => false,
            'plan_chat' => [
                ...array_values((array) ($shot->plan_chat ?? [])),
                ['role' => 'assistant', 'text' => __('What did not work in the keyframes?'), 'stage' => 'plan'],
            ],
        ])->save();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
