<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\ShotRequest;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Http\Resources\Public\ShotResource;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UpdateController
{
    public function update(Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        $shot->setRelation('project', $project);

        return Inertia::render('shots/update', [
            'project' => fn() => ProjectResource::make($project),
            'shot' => fn() => ShotResource::make($shot),
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'keyframes.media'])->withCount('keyframes')->get()
            ),
        ]);
    }

    public function store(ShotRequest $request, Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        if (! $shot->exists) {
            $shot->project_id = $project->id;
            $shot->position = ($project->shots()->max('position') ?? 0) + 1;
        }

        $shot->fill($request->shotAttributes());
        $shot->status = ShotStatus::OPTIONS_PENDING;
        $shot->storyline_options = null;
        $shot->chosen_storyline = null;
        $shot->storyline_error = null;
        $shot->save();

        GenerateStorylineOptions::dispatch($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function authorize(Project $project, Shot $shot): void
    {
        if ($shot->exists) {
            Gate::authorize(ShotPolicy::UPDATE, $shot);

            return;
        }

        Gate::authorize(ShotPolicy::CREATE, [Shot::class, $project]);
    }
}
