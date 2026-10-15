<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\CorrectionSource;
use App\Enums\KeyframeStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeRequest;
use App\Jobs\FollowStoryline;
use App\Jobs\RetimeShot;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use App\Support\Shots\KeyframeChain;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateController
{
    use GuardsBusyShots;

    /**
     * Stage a keyframe anew: its line, its spot and how each of the cast is
     * staged; the description is built from them. The current image is
     * adjusted to the change, so what the change does not touch stays as it
     * is; with `redraw`, or without an image yet, it is drawn again from the
     * description. With `following`, the keyframes drawn from it follow.
     */
    public function store(KeyframeRequest $request, Project $project, Shot $shot, Keyframe $keyframe, KeyframeChain $chain)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'summary');

        $staging = $request->staging();
        $spatial = trim((string) $request->validated('spatial'));
        $previous = $keyframe->fullDescription();
        $changed = trim($previous) !== Keyframe::joined($staging->describe(), $spatial);
        $adjust = ! $request->boolean('redraw') && $keyframe->render() !== null;
        $following = $request->boolean('following');

        if ($adjust && ! $changed) {
            throw ValidationException::withMessages(['summary' => __('Change the keyframe first, or draw it again.')]);
        }

        $keyframe->restage($staging)->forceFill(['spatial' => $spatial !== '' ? $spatial : null])->save();

        // Kept in step, so a full re-render keeps the director's staging. The copy mark belongs to the old one.
        $shot->updatePlannedKeyframe($keyframe->position, array_filter([...$staging->fields(), 'spatial' => $spatial]), $spatial === '' ? ['spatial', 'copied'] : ['copied']);
        $current = $keyframe->fullDescription();

        // The storyline follows the new description first, so the review reads one story.
        if ($changed) {
            FollowStoryline::dispatch($keyframe, $previous);
            // What happens may take longer or shorter now: the shot is timed again.
            RetimeShot::dispatch($shot);
        }

        if ($adjust) {
            $keyframe->forceFill(['status' => KeyframeStatus::DRAWING, 'render_error' => null])->save();
            TweakKeyframeImage::dispatch($keyframe, "The description of this keyframe changed from \"{$previous}\" to \"{$current}\". Change the image so it shows what the new description says; keep everything the change does not touch.", rewrite: true, describedByDirector: true, following: $following);
        } else {
            $chain->redrawFrom($keyframe, $following);
        }

        if ($changed) {
            $keyframe->setRelation('shot', $shot);
            RecordCorrection::record($project, CorrectionSource::DESCRIPTION, "Changed the keyframe description from \"{$previous}\" to \"{$current}\".", $shot, $keyframe, 'Storyline of the shot: ' . ($shot->chosenStoryline()['storyline'] ?? ''));
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
