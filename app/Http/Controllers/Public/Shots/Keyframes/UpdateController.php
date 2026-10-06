<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\CorrectionSource;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeRequest;
use App\Jobs\GenerateKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use Illuminate\Support\Facades\Gate;

class UpdateController
{
    use GuardsBusyShots;

    /**
     * Change the description of a keyframe and render its image again from it.
     */
    public function store(KeyframeRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'description');

        $description = $request->validated('description');
        $previous = $keyframe->description;

        $keyframe->forceFill([
            'description' => $description,
            'rendering' => true,
            'render_error' => null,
        ])->save();

        // Kept in step, so a full re-render keeps the director's wording. The old must show and the copy mark belong to the old wording.
        $shot->updatePlannedKeyframe($keyframe->position, ['description' => $description, 'prompt' => $description], ['must_show', 'copied']);

        GenerateKeyframeImage::dispatch($keyframe);

        if (trim($previous) !== trim($description)) {
            $keyframe->setRelation('shot', $shot);
            RecordCorrection::record($project, CorrectionSource::DESCRIPTION, "Changed the keyframe description from \"{$previous}\" to \"{$description}\".", $shot, $keyframe, "Takeaway of the shot: {$shot->takeaway}.");
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
