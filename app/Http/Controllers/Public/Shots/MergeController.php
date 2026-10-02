<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Requests\Public\MergeShotsRequest;
use App\Http\Requests\Public\ShotTransitionRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\MergeShots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Merges adjacent shots into one, joins its clips again with another
 * transition, and splits it back into its parts.
 */
class MergeController
{
    public function store(MergeShotsRequest $request, Project $project, MergeShots $merger): RedirectResponse
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        /** @var array<int, string> $ids */
        $ids = $request->validated('shots');
        $shots = $project->shots()->with('media')->get()->filter(fn(Shot $shot) => in_array($shot->sqid, $ids, true))->values();

        if ($shots->count() !== count($ids)) {
            throw ValidationException::withMessages(['shots' => __('Pick at least two shots of this project.')]);
        }

        $merged = $merger->merge($project, $shots, (string) $request->validated('title'), $request->transition());

        return redirect()->route('public.shots.view', [$project, $merged]);
    }

    public function update(ShotTransitionRequest $request, Project $project, Shot $shot, MergeShots $merger): RedirectResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->isMerged()) {
            abort(404);
        }

        $merger->rejoin($shot, $request->transition());

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    public function destroy(Project $project, Shot $shot, MergeShots $merger): RedirectResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->isMerged()) {
            abort(404);
        }

        $first = $merger->unmerge($shot);

        return redirect()->route('public.shots.view', [$project, $first]);
    }
}
