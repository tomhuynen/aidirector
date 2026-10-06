<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Concerns;

use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Request;

/**
 * Shot actions return to the shot, or to the decision queue when the
 * decision was made there (`return=decisions`), so the queue can move on.
 */
trait ReturnsToDecisions
{
    protected function afterAction(Project $project, Shot $shot): RedirectResponse
    {
        return Request::input('return') === 'decisions'
            ? redirect()->route('public.projects.decisions', $project)
            : redirect()->route('public.shots.view', [$project, $shot]);
    }
}
