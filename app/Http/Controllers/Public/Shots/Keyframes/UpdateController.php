<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\CorrectionSource;
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
    /**
     * Change the description of a keyframe and render its image again from it.
     */
    public function store(KeyframeRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $description = $request->validated('description');
        $plan = ['title' => $keyframe->title, 'description' => $description, 'prompt' => $description];
        $previous = $keyframe->description;

        $keyframe->forceFill([
            'description' => $description,
            'rendering' => true,
            'render_error' => null,
        ])->save();

        $this->syncPlan($shot, $keyframe, $plan);

        GenerateKeyframeImage::dispatch($keyframe);

        if (trim($previous) !== trim($description)) {
            $keyframe->setRelation('shot', $shot);
            RecordCorrection::record($project, CorrectionSource::DESCRIPTION, "Changed the keyframe description from \"{$previous}\" to \"{$description}\".", $shot, $keyframe, "Takeaway of the shot: {$shot->takeaway}.");
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Keep the planned keyframe in step, so a full re-render keeps the director's wording.
     *
     * @param  array{title: string, description: string, prompt: string}  $plan
     */
    private function syncPlan(Shot $shot, Keyframe $keyframe, array $plan): void
    {
        $keyframes = $shot->storylineKeyframes();
        $index = $keyframe->position - 1;

        if (! isset($keyframes[$index])) {
            return;
        }

        $keyframes[$index] = [...$keyframes[$index], ...$plan];

        $shot->replacePlannedKeyframes($keyframes);
    }
}
