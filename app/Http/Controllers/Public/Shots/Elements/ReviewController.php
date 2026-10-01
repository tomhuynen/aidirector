<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Elements;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\ElementReviewRequest;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewController
{
    /**
     * Apply the director's decisions on the proposed cast and sets, then
     * render the other keyframes with them.
     */
    public function store(ElementReviewRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->status !== ShotStatus::ELEMENTS_READY) {
            throw ValidationException::withMessages(['decisions' => __('There are no cast and sets waiting for review.')]);
        }

        $proposals = $shot->elementProposals();
        $decisions = array_values($request->validated('decisions'));

        if (count($decisions) !== count($proposals)) {
            throw ValidationException::withMessages(['decisions' => __('Decide on every proposed element.')]);
        }

        $library = $project->elements()->get()->keyBy('sqid');
        $keyframes = $shot->keyframes()->get()->keyBy('position');

        DB::connection($shot->getConnectionName())->transaction(function () use ($proposals, $decisions, $library, $keyframes, $project) {
            foreach ($proposals as $index => $proposal) {
                $decision = $decisions[$index];

                $element = match ($decision['action']) {
                    ElementReviewRequest::ADD => $project->elements()->create([
                        'type' => $proposal['type'],
                        'name' => $proposal['name'],
                        'description' => $proposal['description'],
                    ]),
                    ElementReviewRequest::EXISTING => $library->get((string) $decision['element'])
                        ?? throw ValidationException::withMessages(["decisions.{$index}.element" => __('That element is not part of this project.')]),
                    default => null,
                };

                if (! $element instanceof Element) {
                    continue;
                }

                foreach ($proposal['keyframes'] as $position) {
                    $keyframes->get($position)?->elements()->syncWithoutDetaching([$element->id]);
                }
            }
        });

        GenerateRemainingKeyframes::startFor($shot);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
