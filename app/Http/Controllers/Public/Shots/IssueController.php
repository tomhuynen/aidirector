<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Decisions\ShotIssues;
use Illuminate\Support\Facades\Gate;

class IssueController
{
    use ReturnsToDecisions;

    /**
     * Fix a keyframe only where the checks found something wrong with it.
     */
    public function fix(Project $project, Shot $shot, string $group, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->fix($shot, $group);

        return $this->afterAction($project, $shot);
    }

    /**
     * Draw a keyframe again from its description, instead of fixing it.
     */
    public function redraw(Project $project, Shot $shot, string $group, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->redraw($shot, $group);

        return $this->afterAction($project, $shot);
    }

    /**
     * Redraw every keyframe the checks found something wrong with, each once.
     */
    public function fixAll(Project $project, Shot $shot, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->fixAll($shot);

        return $this->afterAction($project, $shot);
    }

    /**
     * Ignore everything the checks found for the shot.
     */
    public function dismissAll(Project $project, Shot $shot, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->dismissAll($shot);

        return $this->afterAction($project, $shot);
    }

    /**
     * Ignore what the checks found for a keyframe, or for the whole shot.
     */
    public function dismiss(Project $project, Shot $shot, string $group, ShotIssues $issues)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $issues->dismiss($shot, $group);

        return $this->afterAction($project, $shot);
    }
}
