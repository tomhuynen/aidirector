<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Findings\ShotIssues;
use Illuminate\Support\Facades\Gate;

class IssueController
{
    /**
     * Redraw a keyframe, told what the checks found wrong with it.
     */
    public function fix(Project $project, Shot $shot, string $group, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->fix($shot, $group);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Redraw every keyframe the checks found something wrong with, each once.
     */
    public function fixAll(Project $project, Shot $shot, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->fixAll($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Ignore everything the checks found for the shot.
     */
    public function dismissAll(Project $project, Shot $shot, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->dismissAll($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Ignore what the checks found for a keyframe, or for the whole shot.
     */
    public function dismiss(Project $project, Shot $shot, string $group, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->dismiss($shot, $group);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
