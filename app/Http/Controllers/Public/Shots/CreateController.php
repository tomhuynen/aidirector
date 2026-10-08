<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ShotStatus;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new shot: it is added at the end of the sequence with an empty plan and
 * opens in the plan chat, where its takeaway and plan are worked out.
 */
class CreateController
{
    /**
     * The page that makes the shot as soon as it opens.
     */
    public function create(Project $project): Response
    {
        Gate::authorize(ShotPolicy::CREATE, [Shot::class, $project]);

        return Inertia::render('shots/create', [
            'project' => fn() => ProjectResource::make($project),
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'media', 'keyframes.media', 'parts.keyframes.media'])->withCount(['keyframes', 'parts'])->get()
            ),
        ]);
    }

    public function store(Project $project): RedirectResponse
    {
        Gate::authorize(ShotPolicy::CREATE, [Shot::class, $project]);

        $shot = $project->allShots()->create([
            'position' => ($project->shots()->max('position') ?? 0) + 1,
            'title' => '',
            'takeaway' => '',
            'status' => ShotStatus::STORYLINE_READY,
            'chosen_storyline' => ['title' => '', 'storyline' => ''],
            'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => []],
        ]);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
