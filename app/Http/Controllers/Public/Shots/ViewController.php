<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\KeyframeResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
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
            'keyframes' => fn() => KeyframeResource::collection(
                $shot->keyframes()->with(['media', 'elements'])->get()->each->setRelation('shot', $shot)
            ),
            'elements' => fn() => ElementResource::collection($project->elements()->with('media')->get()),
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'keyframes.media'])->withCount('keyframes')->get()
            ),
        ]);
    }
}
