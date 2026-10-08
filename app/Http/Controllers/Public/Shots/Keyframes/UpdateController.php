<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\CorrectionSource;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeRequest;
use App\Jobs\FollowStoryline;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\RetimeShot;
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
        $spatial = trim((string) $request->validated('spatial'));
        $previous = $keyframe->fullDescription();
        $changed = trim($previous) !== Keyframe::joined($description, $spatial);
        $adjust = ! $request->boolean('redraw') && $keyframe->render() !== null;

        if ($adjust && ! $changed) {
            throw ValidationException::withMessages(['description' => __('Change the description first, or draw the keyframe again.')]);
        }

        $keyframe->forceFill([
            'description' => $description,
            'spatial' => $spatial !== '' ? $spatial : null,
            'rendering' => true,
            'render_error' => null,
        ])->save();

        // Kept in step, so a full re-render keeps the director's wording. The old must show and the copy mark belong to the old wording.
        $shot->updatePlannedKeyframe($keyframe->position, array_filter(['description' => $description, 'spatial' => $spatial]), $spatial === '' ? ['spatial', 'copied', 'prompt'] : ['copied', 'prompt']);
        $current = $keyframe->fullDescription();

        // The storyline follows the new description first, so the review reads one story.
        if ($changed) {
            FollowStoryline::dispatch($keyframe, $previous);
            // What happens may take longer or shorter now: the shot is timed again.
            RetimeShot::dispatch($shot);
        }

        $adjust
            ? TweakKeyframeImage::dispatch($keyframe, "The description of this keyframe changed from \"{$previous}\" to \"{$current}\". Change the image so it shows what the new description says; keep everything the change does not touch.", rewrite: true, describedByDirector: true)
            : GenerateKeyframeImage::dispatch($keyframe);

        if ($changed) {
            $keyframe->setRelation('shot', $shot);
            RecordCorrection::record($project, CorrectionSource::DESCRIPTION, "Changed the keyframe description from \"{$previous}\" to \"{$current}\".", $shot, $keyframe, "Takeaway of the shot: {$shot->takeaway}.");
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
