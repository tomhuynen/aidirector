<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Style;

use App\Http\Requests\Public\StyleRoundRequest;
use App\Http\Resources\Public\StyleOptionResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\StyleOption;
use App\Support\Style\StartStyleRound;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Rounds of the style exploration. A round is four style sheets; starting
 * one with a parent option branches from that option ("more like this").
 */
class RoundController
{
    public function __construct(
        private readonly StartStyleRound $startStyleRound,
    ) {}

    /**
     * The options of one round, for polling while they render.
     */
    public function index(Project $project, int $round): AnonymousResourceCollection
    {
        Gate::authorize(ProjectPolicy::STYLE, $project);

        $options = $project->styleOptions()->where('round', $round)->with('media')->get()
            ->each(fn(StyleOption $option) => $option->setRelation('project', $project));

        return StyleOptionResource::collection($options);
    }

    public function store(StyleRoundRequest $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize(ProjectPolicy::STYLE, $project);

        $parent = $request->parent($project);

        $options = $this->startStyleRound->start($project, $parent);

        return StyleOptionResource::collection($options->each(function (StyleOption $option) use ($project) {
            $option->setRelation('media', collect())->setRelation('project', $project);
        }));
    }
}
