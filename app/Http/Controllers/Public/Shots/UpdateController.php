<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\ShotRequest;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Http\Resources\Public\ShotResource;
use App\Jobs\GenerateStoryline;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UpdateController
{
    use GuardsBusyShots;

    public function update(Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        $shot->setRelation('project', $project);

        return Inertia::render('shots/update', [
            'project' => fn() => ProjectResource::make($project),
            'shot' => fn() => ShotResource::make($shot),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
            /** The cast and sets the director can ask the storylines to use. */
            'elements' => fn() => ElementResource::collection(
                $project->elements()->with('media')->get()->each->setRelation('project', $project)
            ),
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'keyframes.media', 'parts.keyframes.media'])->withCount(['keyframes', 'parts'])->get()
            ),
        ]);
    }

    public function store(ShotRequest $request, Project $project, Shot $shot)
    {
        $this->authorize($project, $shot);

        if ($shot->exists) {
            $this->ensureShotIdle($shot, 'takeaway');
        } else {
            $shot->project_id = $project->id;
            $shot->position = ($project->shots()->max('position') ?? 0) + 1;
        }

        $shot->fill($request->shotAttributes());
        $shot->storyline_options = null;
        $shot->storyline_error = null;

        // Written by the director: an empty plan to fill in, nothing is generated.
        if ($request->boolean('manual')) {
            $shot->forgetKeyframes();
            $shot->status = ShotStatus::STORYLINE_READY;
            $shot->chosen_storyline = ['title' => $shot->title, 'storyline' => ''];
            $shot->storyline = ['mode' => 'manual', 'framing' => ['spot' => (string) $shot->notes, 'seconds' => null], 'keyframes' => []];
            $shot->save();

            return redirect()->route('public.shots.view', [$project, $shot]);
        }

        $shot->status = ShotStatus::STORYLINE_PENDING;
        $shot->chosen_storyline = null;
        $shot->storyline = null;
        $shot->save();

        // The planner drafts the storyline and keyframes; the director checks the draft before anything is drawn.
        GenerateStoryline::dispatch($shot, draw: false);

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
