<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Ai\LayoutPainter;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LayoutController
{
    use GuardsBusyShots;

    /**
     * Read where the cast stands in a keyframe that has no layout yet, so its
     * people and objects can be picked and moved.
     */
    public function store(Project $project, Shot $shot, Keyframe $keyframe, LayoutPainter $layouts)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'move');

        $render = $keyframe->render();

        if ($render === null) {
            throw ValidationException::withMessages(['move' => __('There is no image to move anything in yet.')]);
        }

        if (($keyframe->layout['cast'] ?? []) === []) {
            $keyframe->setRelation('shot', $shot->load('project'));
            $keyframe->forceFill(['layout' => $layouts->read($keyframe, $render)->layout()->toArray()])->save();
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
