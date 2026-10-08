<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Http\Requests\Public\PlateAdjustRequest;
use App\Jobs\AdjustPlateOption;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PlateController
{
    use ReturnsToDecisions;

    /**
     * Choose the empty place the shot starts from, and draw keyframe 1 on it.
     */
    public function choose(Request $request, Project $project, Shot $shot, KeyframePainter $painter)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $request->validate(['plate' => ['required', 'integer']]);

        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY || $shot->hasChosenPlate()) {
            throw ValidationException::withMessages(['plate' => __('A place can be chosen once the places are drawn.')]);
        }

        $option = $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $request->integer('plate'))
            ?? throw ValidationException::withMessages(['plate' => __('That place does not belong to this shot.')]);

        if ($shot->keyframes()->where('position', 1)->where('rendering', true)->exists()) {
            throw ValidationException::withMessages(['plate' => __('Wait until the adjusted place is drawn.')]);
        }

        $plate = $option->copy($shot, Shot::PLATE);
        $plate->setCustomProperty(Shot::PLATE_CHOSEN, true)->setCustomProperty('option', $option->id)->save();

        GenerateRemainingKeyframes::startOnPlate($shot, $painter);

        // Shots of the sequence that play in this place can be drawn now.
        Shot::drawWaitingShots($project->id);

        return $this->afterAction($project, $shot);
    }

    /**
     * Adjust one place before choosing it. The adjusted place is added as a
     * new one, so the original stays available.
     */
    public function adjust(PlateAdjustRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY || $shot->hasChosenPlate()) {
            throw ValidationException::withMessages(['instruction' => __('A place can be adjusted while the places wait for a choice.')]);
        }

        $option = $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $request->integer('plate'))
            ?? throw ValidationException::withMessages(['plate' => __('That place does not belong to this shot.')]);
        $first = $shot->keyframes()->firstOrFail();

        if ($first->rendering) {
            throw ValidationException::withMessages(['instruction' => __('Wait until the current adjustment is done.')]);
        }

        $first->forceFill(['rendering' => true, 'render_error' => null])->save();
        $shot->forceFill(['storyline_error' => null])->save();

        AdjustPlateOption::dispatch($shot, $option->id, (string) $request->validated('instruction'));

        return $this->afterAction($project, $shot);
    }

    /**
     * Go back to the places, dropping keyframe 1 drawn on the chosen one.
     */
    public function reset(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $first = $shot->keyframes()->firstOrFail();

        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY || ! $shot->hasChosenPlate() || $first->rendering) {
            throw ValidationException::withMessages(['plate' => __('Another place can be chosen while keyframe 1 waits for confirmation.')]);
        }

        $shot->clearMediaCollection(Shot::PLATE);
        $first->clearMediaCollection(Keyframe::RENDERS);
        $first->forceFill(['render_id' => null, 'render_error' => null])->save();
        $shot->forceFill(['storyline_error' => null])->save();

        return $this->afterAction($project, $shot);
    }
}
