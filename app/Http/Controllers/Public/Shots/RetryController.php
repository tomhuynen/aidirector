<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateStoryline;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RetryController
{
    use ReturnsToDecisions;

    /**
     * Try again what failed: the plan, the options for
     * keyframe 1 when none could be drawn, or each keyframe that failed.
     */
    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $failed = $shot->keyframes()->with('media')->get()
            ->filter(fn(Keyframe $keyframe) => filled($keyframe->render_error) && ! $keyframe->rendering);

        if ($shot->status === ShotStatus::DRAFT && filled($shot->storyline_error)) {
            $shot->forceFill(['status' => ShotStatus::STORYLINE_PENDING, 'storyline_error' => null])->save();
            GenerateStoryline::dispatch($shot);
        } elseif (! $shot->drawsStandalone() && $failed->contains(fn(Keyframe $keyframe) => $keyframe->position === 1 && $keyframe->renders()->isEmpty())) {
            $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_PENDING, 'storyline_error' => null])->save();
            GenerateKeyframes::dispatch($shot);
        } elseif ($failed->isNotEmpty()) {
            $failed->each(function (Keyframe $keyframe) {
                $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();
                GenerateKeyframeImage::dispatch($keyframe);
            });
        } else {
            throw ValidationException::withMessages(['retry' => __('Nothing failed on this shot.')]);
        }

        return $this->afterAction($project, $shot);
    }
}
