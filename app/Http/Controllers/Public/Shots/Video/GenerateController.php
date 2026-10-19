<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Video;

use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Jobs\GenerateVideo;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\MergeShots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GenerateController
{
    use GuardsBusyShots;
    /**
     * Render the video from the shot's keyframes at the project's resolution.
     * A shot that renders with its sequence renders the whole sequence as
     * one clip: its shots are merged and the merged shot opens.
     */
    public function store(Project $project, Shot $shot, MergeShots $merger)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->rendersInSequence()) {
            return $this->renderSequence($project, $shot, $merger);
        }

        $keyframes = $shot->keyframes()->with('media')->get();

        if ($keyframes->isEmpty() || $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->isBusy() || $keyframe->render() === null)) {
            throw ValidationException::withMessages([
                'video' => __('Wait until every keyframe has an image.'),
            ]);
        }

        if ($shot->status === ShotStatus::VIDEO_PENDING) {
            throw ValidationException::withMessages([
                'video' => __('The video is already being rendered.'),
            ]);
        }

        $this->ensureShotIdle($shot, 'video');

        $shot->forgetVideo();

        $shot->forceFill(['status' => ShotStatus::VIDEO_PENDING])->save();

        GenerateVideo::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function renderSequence(Project $project, Shot $shot, MergeShots $merger): RedirectResponse
    {
        $shots = $project->shots()->with('media')->where('group_key', $shot->group_key)->orderBy('position')->get()
            ->filter(fn(Shot $member) => $member->rendersInSequence())
            ->values();
        // A start frame drawn as options counts once the director has chosen one.
        $waiting = $shots->reject(fn(Shot $member) => in_array($member->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_PENDING], true));

        if ($waiting->isNotEmpty()) {
            throw ValidationException::withMessages([
                'video' => __('Choose the start frame of :shots first: the sequence renders from the start frame of every shot.', ['shots' => $waiting->map(fn(Shot $member) => $member->code())->join(', ')]),
            ]);
        }

        try {
            $merged = $merger->merge($project, $shots, $shots->first()->title, ShotTransition::CUT);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['video' => collect($exception->errors())->flatten()->first()]);
        }

        return redirect()->route('public.shots.view', [$project, $merged]);
    }
}
