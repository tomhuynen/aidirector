<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Video;

use App\Enums\ShotStatus;
use App\Jobs\GenerateVideo;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GenerateController
{
    /**
     * Render the video from the shot's keyframes.
     */
    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $keyframes = $shot->keyframes()->with('media')->get();

        if ($keyframes->isEmpty() || $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->rendering || $keyframe->render() === null)) {
            throw ValidationException::withMessages([
                'video' => __('Wait until every keyframe has an image.'),
            ]);
        }

        if ($shot->status === ShotStatus::VIDEO_PENDING) {
            throw ValidationException::withMessages([
                'video' => __('The video is already being rendered.'),
            ]);
        }

        $shot->forgetVideo();

        $shot->forceFill(['status' => ShotStatus::VIDEO_PENDING])->save();

        GenerateVideo::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
