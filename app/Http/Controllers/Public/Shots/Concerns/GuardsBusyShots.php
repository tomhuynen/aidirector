<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Concerns;

use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Validation\ValidationException;

/**
 * Refuses actions while a job is still working on the shot. Starting over
 * then would throw away paid work, or the running job would overwrite the
 * new state when it finishes.
 */
trait GuardsBusyShots
{
    /**
     * For actions that start the shot over: nothing may be planning, drawing or rendering.
     *
     * @throws ValidationException
     */
    protected function ensureShotIdle(Shot $shot, string $field): void
    {
        if ($shot->status->isWorking() || $shot->keyframes()->where('rendering', true)->exists()) {
            throw ValidationException::withMessages([
                $field => __('Wait until the shot is done working.'),
            ]);
        }
    }

    /**
     * For actions on one keyframe: that keyframe is not being drawn, and the
     * shot is not busy drawing its keyframes or rendering its video. Other
     * keyframes may be adjusted at the same time.
     *
     * @throws ValidationException
     */
    protected function ensureKeyframeIdle(Shot $shot, Keyframe $keyframe, string $field): void
    {
        if ($keyframe->rendering) {
            throw ValidationException::withMessages([
                $field => __('Wait until this keyframe is drawn.'),
            ]);
        }

        if ($shot->status->isWorking()) {
            throw ValidationException::withMessages([
                $field => __('Wait until the shot is done working.'),
            ]);
        }
    }
}
