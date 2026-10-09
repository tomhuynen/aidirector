<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Http\Requests\Public\FirstKeyframeAdjustRequest;
use App\Http\Requests\Public\KeyframeRenderRequest;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\FirstKeyframeChoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The step between planning and rendering: the director picks one of the
 * options drawn for keyframe 1, or asks for more.
 */
class FirstController
{
    use ReturnsToDecisions;

    /**
     * Choose an option for keyframe 1 and render the other keyframes from it.
     */
    public function choose(KeyframeRenderRequest $request, Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $choice->ensureChoosing($shot, 'render');
        $choice->useFirst($shot, $this->render($shot, $request->integer('render')));

        return $this->afterAction($project, $shot);
    }

    /**
     * Draw another batch of options for keyframe 1.
     */
    public function more(Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $choice->more($shot);

        return $this->afterAction($project, $shot);
    }

    /**
     * Adjust one option for keyframe 1 before choosing it. The adjusted
     * version is added as a new option, so the original stays available.
     */
    public function adjust(FirstKeyframeAdjustRequest $request, Project $project, Shot $shot, FirstKeyframeChoice $choice)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $choice->ensureChoosing($shot, 'instruction');
        $choice->adjust($shot, $this->render($shot, $request->integer('render')), (string) $request->validated('instruction'), $request->boolean('rewrite'));

        return $this->afterAction($project, $shot);
    }

    private function render(Shot $shot, int $id): Media
    {
        return $shot->keyframes()->with('media')->firstOrFail()->renders()->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['render' => __('That option does not belong to the first keyframe.')]);
    }
}
