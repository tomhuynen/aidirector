<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Http\Requests\Public\KeyframeRenderRequest;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RenderController
{
    /**
     * Pick one of the keyframe's renders as the chosen version.
     */
    public function store(KeyframeRenderRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $render = $keyframe->renders()->firstWhere('id', $request->integer('render'));

        if ($render === null) {
            throw ValidationException::withMessages([
                'render' => __('That version does not belong to this keyframe.'),
            ]);
        }

        $keyframe->forceFill(['render_id' => $render->id])->save();

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
