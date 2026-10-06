<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;

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

        $remaining = $project->shots()->orderBy('position')->get();
        $remaining->each(fn(Shot $sibling, int $index) => $sibling->update(['position' => $index + 1]));

        $next = $remaining->get(min($position, $remaining->count()) - 1);

        return $next === null
            ? redirect()->route('public.shots.create', $project)
            : redirect()->route('public.shots.view', [$project, $next]);
    }
}
