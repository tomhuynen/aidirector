<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\KeyframeRenderRequest;
use App\Jobs\DetectElements;
use App\Jobs\GenerateKeyframes;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The step between planning and rendering: the director picks one of the
 * options drawn for keyframe 1, or asks for more.
 */
class FirstController
{
    /**
     * Choose an option for keyframe 1; its cast and sets are found next, then the other keyframes render.
     */
    public function choose(KeyframeRenderRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $this->ensureChoosing($shot, 'render');

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $render = $first->renders()->firstWhere('id', $request->integer('render'))
            ?? throw ValidationException::withMessages(['render' => __('That option does not belong to the first keyframe.')]);

        $first->forceFill(['render_id' => $render->id])->save();

        $shot->forceFill([
            'status' => ShotStatus::ELEMENTS_PENDING,
            'storyline_error' => null,
        ])->save();

        DetectElements::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Draw another batch of options for keyframe 1.
     */
    public function more(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $this->ensureChoosing($shot, 'keyframes');

        $shot->keyframes()->where('position', 1)->update(['rendering' => true, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateKeyframes::dispatch($shot, more: true);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function ensureChoosing(Shot $shot, string $field): void
    {
        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY) {
            throw ValidationException::withMessages([$field => __('The first keyframe is not waiting for a choice.')]);
        }
    }
}
