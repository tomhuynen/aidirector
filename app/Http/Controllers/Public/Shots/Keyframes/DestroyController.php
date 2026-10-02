<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DestroyController
{
    /**
     * Remove a keyframe with its images; the ones after it move up.
     */
    public function destroy(Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $keyframes = $shot->keyframes()->get();

        if ($keyframes->count() < 2 || ! $shot->canArrangeKeyframes($keyframes)) {
            throw ValidationException::withMessages([
                'keyframe' => __('A keyframe can be deleted once every keyframe is rendered, and a shot keeps at least one.'),
            ]);
        }

        $remaining = $keyframes->reject(fn(Keyframe $other) => $other->is($keyframe))->values()->all();

        $shot->arrangeKeyframes($remaining);
        $keyframe->delete();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
