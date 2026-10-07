<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\CorrectionSource;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeRequest;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateController
{
    use GuardsBusyShots;

    /**
     * Change the description of a keyframe. The current image is adjusted to
     * the change, so what the change does not touch stays as it is; with
     * `redraw`, or without an image yet, it is drawn again from the description.
     */
    public function store(KeyframeRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'description');

        $description = $request->validated('description');
        $previous = $keyframe->description;
        $changed = trim($previous) !== trim($description);
        $adjust = ! $request->boolean('redraw') && $keyframe->render() !== null;

        if ($adjust && ! $changed) {
            throw ValidationException::withMessages(['description' => __('Change the description first, or draw the keyframe again.')]);
        }

        $keyframe->forceFill([
            'description' => $description,
            'rendering' => true,
            'render_error' => null,
        ])->save();

        // Kept in step, so a full re-render keeps the director's wording. The old must show and the copy mark belong to the old wording.
        $shot->updatePlannedKeyframe($keyframe->position, ['description' => $description], ['must_show', 'copied', 'prompt']);

        $adjust
            ? TweakKeyframeImage::dispatch($keyframe, "The description of this keyframe changed from \"{$previous}\" to \"{$description}\". Change the image so it shows what the new description says; keep everything the change does not touch.", rewrite: true, describedByDirector: true)
            : GenerateKeyframeImage::dispatch($keyframe);

        if ($changed) {
            $keyframe->setRelation('shot', $shot);
            RecordCorrection::record($project, CorrectionSource::DESCRIPTION, "Changed the keyframe description from \"{$previous}\" to \"{$description}\".", $shot, $keyframe, "Takeaway of the shot: {$shot->takeaway}.");
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
