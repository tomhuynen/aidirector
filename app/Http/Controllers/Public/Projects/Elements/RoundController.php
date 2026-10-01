<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Models\ElementRound;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\Elements\ElementRoundState;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A cast and sets round's current state, polled by the chat while its
 * suggestions are written and rendered.
 */
class RoundController
{
    public function show(Project $project, ElementRound $elementRound, ElementRoundState $state): JsonResponse
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        $elementRound->setRelation('project', $project);

        /** @var array{id: string, type: 'person'|'place'|'object', label: string, status: 'suggesting'|'ready'|'picked'|'skipped'|'failed', error: string|null, pollUrl: string, pickUrl: string, options: array<int, array{id: string, name: string, description: string, status: 'pending'|'ready'|'failed', picked: bool, fromPhoto: bool, thumbnailUrl: string|null, imageUrl: string|null}>} $round */
        $round = $state->for($elementRound);

        return response()->json($round);
    }
}
