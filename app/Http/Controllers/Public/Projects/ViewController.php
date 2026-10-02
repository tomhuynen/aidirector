<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Enums\ElementType;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\Video\VideoFormats;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    /**
     * The project overview: its style, cast and sets and shots, with the
     * way into the editor. A project still in setup goes back to its intake chat.
     */
    public function view(Project $project)
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        if ($project->needsSetup()) {
            return redirect()->route('public.projects.setup', $project);
        }

        $project->load(['media', 'shots' => fn($shots) => $shots->with(['project', 'keyframes.media'])->withCount('keyframes')]);

        return Inertia::render('projects/view', [
            'project' => fn() => ProjectResource::make($project),
            'shots' => fn() => ShotListItemResource::collection($project->shots),
            /** @var array{aspectRatios: array<int, array{value: string, name: string}>, resolutions: array<int, string>, sizes: array<string, array{width: int, height: int}>} */
            'videoFormats' => fn() => VideoFormats::catalogue(),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
            'elements' => fn() => ElementResource::collection(
                $project->elements()->with('media')->get()->each->setRelation('project', $project)
            ),
        ]);
    }
}
