<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Ai\Briefs\KeyframeImageBrief;
use App\Http\Requests\Public\KeyframeRequest;
use App\Jobs\GenerateKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;

class UpdateController
{
    /**
     * Change the description of a keyframe and render its image again from it.
     */
    public function store(KeyframeRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);

        $description = $request->validated('description');
        $plan = ['title' => $keyframe->title, 'description' => $description, 'prompt' => $description];

        $keyframe->forceFill([
            'description' => $description,
            'prompt' => KeyframeImageBrief::for(
                $shot,
                $plan,
                withStyleReference: $project->styleReference() !== null,
                withFirstKeyframe: $keyframe->position > 1,
            ),
            'rendering' => true,
            'render_error' => null,
        ])->save();

        $this->syncPlan($shot, $keyframe, $plan);

        GenerateKeyframeImage::dispatch($keyframe);

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

        $keyframes[$index] = $plan;

        $shot->forceFill(['storyline' => ['keyframes' => $keyframes]])->save();
    }
}
