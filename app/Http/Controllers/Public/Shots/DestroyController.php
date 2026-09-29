<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    public function destroy(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::DESTROY, $shot);

        $shot->delete();

        $project->shots()
            ->orderBy('position')
            ->get()
            ->each(fn(Shot $sibling, int $index) => $sibling->update(['position' => $index + 1]));

        return redirect()->route('public.projects.view', $project);
    }
}
