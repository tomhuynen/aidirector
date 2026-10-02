<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Http\Requests\Public\ReorderKeyframesRequest;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReorderController
{
    /**
     * Put the keyframes of a shot in the order the director dragged them into.
     */
    public function store(ReorderKeyframesRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $keyframes = $shot->keyframes()->get()->keyBy('sqid');
        $ordered = $request->validated('keyframes');

        if (count($ordered) !== $keyframes->count() || $keyframes->keys()->diff($ordered)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'keyframes' => __('The keyframe order does not match the keyframes of this shot.'),
            ]);
        }

        if (! $shot->canArrangeKeyframes($keyframes->values())) {
            throw ValidationException::withMessages([
                'keyframes' => __('Keyframes can be moved once every keyframe is rendered.'),
            ]);
        }

        /** @var list<Keyframe> $arranged */
        $arranged = array_map(fn(string $sqid) => $keyframes->get($sqid), $ordered);

        $shot->arrangeKeyframes($arranged);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
