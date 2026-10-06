<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeRenderRequest;
use App\Jobs\ReviewShot;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RenderController
{
    use GuardsBusyShots;

    /**
     * Pick one of the keyframe's renders as the chosen version.
     */
    public function store(KeyframeRenderRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'render');

        // Keyframe 1 is chosen through its options until the other keyframes are drawn.
        if (! in_array($shot->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true)) {
            throw ValidationException::withMessages([
                'render' => __('A version can be chosen once every keyframe is drawn.'),
            ]);
        }

        $render = $keyframe->renders()->firstWhere('id', $request->integer('render'));

        if ($render === null) {
            throw ValidationException::withMessages([
                'render' => __('That version does not belong to this keyframe.'),
            ]);
        }

        $keyframe->forceFill(['render_id' => $render->id])->save();
        ReviewShot::after($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
