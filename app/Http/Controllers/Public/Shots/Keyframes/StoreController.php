<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\NewKeyframeRequest;
use App\Jobs\RetimeShot;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StoreController
{
    /**
     * Add a keyframe at the end of the shot and render it to match keyframe 1.
     */
    public function store(NewKeyframeRequest $request, Project $project, Shot $shot, KeyframeChain $chain)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $keyframes = $shot->keyframes()->with('media')->get();

        if (! self::canAddTo($shot, $keyframes->count(), $keyframes->first()?->render() !== null, $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->isBusy()))) {
            throw ValidationException::withMessages([
                'summary' => __('A keyframe can only be added when every keyframe is rendered and the shot has room for more.'),
            ]);
        }

        // It plays at the spot of the keyframe before it; who does what is in its line until the director stages them.
        $staging = new KeyframeStaging(trim((string) $request->validated('summary')), $keyframes->sortBy('position')->last()?->staged()->place ?? '');
        $plan = array_filter([
            'title' => $request->validated('title'),
            ...$staging->fields(),
            'spatial' => trim((string) $request->validated('spatial')),
        ]);

        $position = (int) $keyframes->max('position') + 1;

        $keyframe = $shot->keyframes()->create([
            'position' => $position,
            'title' => $plan['title'],
            ...$staging->fields(),
            'spatial' => $plan['spatial'] ?? null,
        ]);

        $shot->appendPlannedKeyframe($plan);
        // One more step, described already: the shot is timed again.
        RetimeShot::dispatch($shot);

        // Drawn from the keyframe before it, once that one is ready.
        $chain->link($shot);
        $chain->draw($keyframe->refresh());

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * A keyframe can be added once keyframe 1 is chosen and rendered, nothing is
     * rendering, and the shot is below the maximum the director may add by hand.
     */
    public static function canAddTo(Shot $shot, int $count, bool $firstRendered, bool $anyRendering): bool
    {
        return in_array($shot->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true)
            && ! $shot->isPresenter()
            && $count > 0
            && $count < (int) Config::get('pipeline.keyframes.max_manual')
            && $firstRendered
            && ! $anyRendering;
    }
}
