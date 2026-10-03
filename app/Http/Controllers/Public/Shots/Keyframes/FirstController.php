<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\FirstKeyframeAdjustRequest;
use App\Http\Requests\Public\KeyframeRenderRequest;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\TweakKeyframeImage;
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
     * Choose an option for keyframe 1 and render the other keyframes from it.
     */
    public function choose(KeyframeRenderRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $this->ensureChoosing($shot, 'render');

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $render = $first->renders()->firstWhere('id', $request->integer('render'))
            ?? throw ValidationException::withMessages(['render' => __('That option does not belong to the first keyframe.')]);

        if ($first->rendering) {
            throw ValidationException::withMessages(['render' => __('Wait until the adjusted option is drawn.')]);
        }

        $first->forceFill(['render_id' => $render->id])->save();

        GenerateRemainingKeyframes::startFor($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Draw another batch of options for keyframe 1.
     */
    public function more(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $this->ensureChoosing($shot, 'keyframes');

        if ($shot->keyframes()->where('position', 1)->where('rendering', true)->exists()) {
            throw ValidationException::withMessages(['keyframes' => __('Wait until the adjusted option is drawn.')]);
        }

        $shot->keyframes()->where('position', 1)->update(['rendering' => true, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
            'storyline_error' => null,
        ])->save();

        GenerateKeyframes::dispatch($shot, more: true);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Adjust one option for keyframe 1 before choosing it. The adjusted
     * version is added as a new option, so the original stays available.
     */
    public function adjust(FirstKeyframeAdjustRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $this->ensureChoosing($shot, 'instruction');

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $render = $first->renders()->firstWhere('id', $request->integer('render'))
            ?? throw ValidationException::withMessages(['render' => __('That option does not belong to the first keyframe.')]);

        if ($first->rendering) {
            throw ValidationException::withMessages(['instruction' => __('Wait until the current adjustment is done.')]);
        }

        $first->forceFill(['rendering' => true, 'render_error' => null])->save();

        TweakKeyframeImage::dispatch($first, $request->validated('instruction'), $render->id);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function ensureChoosing(Shot $shot, string $field): void
    {
        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY) {
            throw ValidationException::withMessages([$field => __('The first keyframe is not waiting for a choice.')]);
        }
    }
}
