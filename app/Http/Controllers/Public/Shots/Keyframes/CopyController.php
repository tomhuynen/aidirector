<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CopyController
{
    use GuardsBusyShots;

    /**
     * Put a copy of a keyframe right after it, at any moment: the same plan
     * and cast and sets, and its chosen image when it has one. Nothing is
     * drawn; a copy without an image is drawn along with the others.
     */
    public function store(Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $keyframes = $shot->keyframes()->with(['media', 'elements'])->get();

        // Copying numbers the keyframes again, which running jobs that know a keyframe by its position must not see.
        if (! $shot->canArrangeKeyframes($keyframes)) {
            throw ValidationException::withMessages([
                'keyframe' => __('A keyframe can be copied once every keyframe is rendered.'),
            ]);
        }

        if ($keyframes->count() >= (int) Config::get('pipeline.keyframes.max_manual')) {
            throw ValidationException::withMessages([
                'keyframe' => __('The shot has no room for another keyframe.'),
            ]);
        }

        // It takes the original's position for now, so it gets the same plan when the keyframes are numbered again.
        $copy = $keyframe->replicate(['render_id', 'prompt']);
        $copy->forceFill(['rendering' => false, 'render_error' => null, 'render_stage' => null, 'render_note' => null])->save();
        $copy->elements()->sync($keyframe->elements->modelKeys());

        $image = $keyframe->render();

        if ($image !== null) {
            $copy->forceFill(['render_id' => $image->copy($copy, Keyframe::RENDERS)->id])->save();
        }

        $original = $keyframes->search(fn(Keyframe $other) => $other->is($keyframe));
        $order = $keyframes->values()->all();
        array_splice($order, $original + 1, 0, [$copy]);

        $shot->arrangeKeyframes($order);

        // Until the director describes it, the copy repeats the original's plan; marked as such so the check and review do not judge it by that text.
        $shot->updatePlannedKeyframe($copy->refresh()->position, ['copied' => true], ['must_show']);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
