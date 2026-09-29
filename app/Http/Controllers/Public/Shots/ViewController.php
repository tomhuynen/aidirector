<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotResource;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    public function view(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::VIEW, $shot);

        $shot->setRelation('project', $project);

        return Inertia::render('shots/view', [
            'project' => fn() => ProjectResource::make($project),
            'shot' => fn() => ShotResource::make($shot),
            /** @var array<int, array{id: string, position: int, title: string, status: string, url: string}> */
            'siblings' => fn() => $project->shots()
                ->with('project')
                ->get()
                ->map(fn(Shot $sibling) => [
                    'id' => $sibling->sqid,
                    'position' => $sibling->position,
                    'title' => $sibling->title,
                    'status' => $sibling->status,
                    'url' => route('public.shots.view', [$project, $sibling]),
                ])
                ->all(),
        ]);
    }
}
