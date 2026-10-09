<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Http\Requests\Public\PlateAdjustRequest;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\FirstKeyframeChoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PlateController
{
    use ReturnsToDecisions;

    /**
     * Choose the empty place the shot starts from, and draw keyframe 1 on it.
     */
    public function choose(Request $request, Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $request->validate(['plate' => ['required', 'integer']]);

        $choice->choosePlace($shot, $this->option($shot, $request->integer('plate')));

        return $this->afterAction($project, $shot);
    }

    /**
     * Adjust one place before choosing it. The adjusted place is added as a
     * new one, so the original stays available.
     */
    public function adjust(PlateAdjustRequest $request, Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $choice->adjust($shot, $this->option($shot, $request->integer('plate')), (string) $request->validated('instruction'));

        return $this->afterAction($project, $shot);
    }

    /**
     * Go back to the places, dropping keyframe 1 drawn on the chosen one.
     */
    public function reset(Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $choice->anotherPlace($shot);

        return $this->afterAction($project, $shot);
    }

    private function option(Shot $shot, int $id): Media
    {
        return $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['plate' => __('That place does not belong to this shot.')]);
    }
}
