<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * How a shot hands its end frame on to the shots that continue from it: the
 * director confirms the end frame before they are drawn, and after it
 * changed decides whether the shots drawn from the earlier version are
 * drawn again or kept.
 */
class ContinuationController
{
    /**
     * Confirm the end frame as it is drawn now: the shots that continue from it are drawn.
     */
    public function confirm(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->canConfirmEndFrame()) {
            throw ValidationException::withMessages(['confirm' => __('The end frame can be confirmed once it is drawn and checked.')]);
        }

        $shot->setRelation('project', $project)->confirmEndFrame();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Start the shots that continue from an earlier version of the end frame over, and every shot after them in the sequence.
     */
    public function reset(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->staleFollowers()->isEmpty()) {
            throw ValidationException::withMessages(['followers' => __('No shot was drawn from an earlier version of this end frame.')]);
        }

        $shot->resetFollowers();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Keep the shots that continue from an earlier version of the end frame as they are.
     */
    public function keep(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->staleFollowers()->isEmpty()) {
            throw ValidationException::withMessages(['followers' => __('No shot was drawn from an earlier version of this end frame.')]);
        }

        $shot->acceptFollowers();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
