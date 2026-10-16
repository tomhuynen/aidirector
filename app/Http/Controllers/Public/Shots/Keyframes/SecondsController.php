<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\KeyframeSecondsRequest;
use App\Jobs\GenerateVideo;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SecondsController
{
    /**
     * Sets how long one keyframe lasts; the shot lasts as long as its
     * keyframes together. An insert's clip costs nothing, so it is made again
     * at once; any other video keeps its length until it is rendered again.
     */
    public function store(KeyframeSecondsRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->kindOrScene()->timedByHand()) {
            throw ValidationException::withMessages(['seconds' => __('The planner times this shot; only a close-up or an insert is timed by hand.')]);
        }

        if ($shot->status === ShotStatus::VIDEO_PENDING) {
            throw ValidationException::withMessages(['seconds' => __('Wait until the video is rendered.')]);
        }

        // Every keyframe keeps the length it shows now; only this one changes.
        $keyframes = $shot->keyframes()->get();
        $seconds = array_combine($keyframes->sortBy('position')->pluck('id')->all(), $shot->keyframeSeconds($keyframes));
        $seconds[$keyframe->id] = $request->seconds();
        $total = array_sum($seconds);
        [$min, $max] = [(int) Config::get('pipeline.video.min_duration'), (int) Config::get('pipeline.video.max_duration')];

        if ($total < $min || $total > $max) {
            throw ValidationException::withMessages(['seconds' => __('The shot lasts :total s that way; it can last from :min to :max s.', ['total' => $total, 'min' => $min, 'max' => $max])]);
        }

        foreach ($keyframes as $other) {
            $other->forceFill(['seconds' => $seconds[$other->id]])->save();
        }

        $shot->fitDurationTo($keyframes);

        if ($shot->isInsert() && $shot->video() !== null) {
            $shot->forgetVideo();
            $shot->forceFill(['status' => ShotStatus::VIDEO_PENDING])->save();

            GenerateVideo::dispatch($shot);
        }

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
