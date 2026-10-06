<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeTweakRequest;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TweakController
{
    use GuardsBusyShots;

    /**
     * Correct the current render of a keyframe with a small change.
     */
    public function store(KeyframeTweakRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'instruction');

        if ($keyframe->render() === null) {
            throw ValidationException::withMessages([
                'instruction' => __('There is no image to adjust yet.'),
            ]);
        }

        $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();

        TweakKeyframeImage::dispatch($keyframe, $request->validated('instruction'), rewrite: $request->boolean('rewrite'));

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
