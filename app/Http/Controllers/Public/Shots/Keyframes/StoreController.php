<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\NewKeyframeRequest;
use App\Jobs\GenerateKeyframeImage;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StoreController
{
    /**
     * Add a keyframe at the end of the shot and render it to match keyframe 1.
     */
    public function store(NewKeyframeRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $keyframes = $shot->keyframes()->with('media')->get();

        if (! self::canAddTo($shot, $keyframes->count(), $keyframes->first()?->render() !== null, $keyframes->contains('rendering', true))) {
            throw ValidationException::withMessages([
                'description' => __('A keyframe can only be added when every keyframe is rendered and the shot has room for more.'),
            ]);
        }

        $plan = [
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'prompt' => $request->validated('description'),
        ];

        $position = (int) $keyframes->max('position') + 1;

        $keyframe = $shot->keyframes()->create([
            'position' => $position,
            'title' => $plan['title'],
            'description' => $plan['description'],
            'rendering' => true,
        ]);

        $shot->forceFill(['storyline' => ['keyframes' => [...$shot->storylineKeyframes(), $plan]]])->save();

        GenerateKeyframeImage::dispatch($keyframe);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * A keyframe can be added once keyframe 1 is chosen and rendered, nothing is
     * rendering, and the shot is below the maximum number of keyframes.
     */
    public static function canAddTo(Shot $shot, int $count, bool $firstRendered, bool $anyRendering): bool
    {
        return in_array($shot->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true)
            && $count > 0
            && $count < (int) Config::get('pipeline.keyframes.max')
            && $firstRendered
            && ! $anyRendering;
    }
}
