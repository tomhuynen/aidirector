<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Requests\Public\DestroyShotsRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DestroyController
{
    /**
     * Delete the shot and stay in the editor on the shot that took its place,
     * the one before it when it was the last, or a new shot when none are left.
     */
    public function destroy(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::DESTROY, $shot);

        $position = $shot->position;
        $shot->delete();

        return self::afterDeleting($project, $position);
    }

    /**
     * Delete the shots ticked in the list. The editor stays on the open shot
     * when it is kept, otherwise on the shot that took the first one's place.
     */
    public function destroyMany(DestroyShotsRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        /** @var array<int, string> $ids */
        $ids = $request->validated('shots');
        $shots = $project->shots()->get()->filter(fn(Shot $shot) => in_array($shot->sqid, $ids, true))->values();

        if ($shots->count() !== count($ids)) {
            throw ValidationException::withMessages(['shots' => __('Pick shots of this project.')]);
        }

        $shots->each(fn(Shot $shot) => Gate::authorize(ShotPolicy::DESTROY, $shot->setRelation('project', $project)));

        $position = (int) $shots->min('position');
        $shots->each->delete();

        $current = $request->validated('current');
        $kept = $current === null || in_array($current, $ids, true)
            ? null
            : $project->shots()->get()->first(fn(Shot $shot) => $shot->sqid === $current);

        return self::afterDeleting($project, $position, $kept);
    }

    /**
     * Close the gaps the deleted shots left, and open the shot to stay on.
     */
    private static function afterDeleting(Project $project, int $position, ?Shot $stayOn = null)
    {
        /** @var Collection<int, Shot> $remaining */
        $remaining = $project->shots()->orderBy('position')->get();
        $remaining->each(fn(Shot $sibling, int $index) => $sibling->update(['position' => $index + 1]));

        $next = $stayOn ?? $remaining->get(min($position, $remaining->count()) - 1);

        return $next === null
            ? redirect()->route('public.shots.create', $project)
            : redirect()->route('public.shots.view', [$project, $next]);
    }
}
