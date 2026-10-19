<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\PlanSequenceRequest;
use App\Jobs\GenerateStoryline;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\SceneDirection;
use App\Support\Shots\SequenceDirecting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The director's answer to a sequence the scene director laid out in the
 * chat: accepted, the shots are made with their plans and the cast and sets
 * the director ticked, this shot first, and drawn; accepted with cast or
 * sets unticked, the scene director directs the story again without them;
 * declined, nothing changes.
 */
class SequenceController
{
    /** Directing the story again takes the scene director up to a minute or more. */
    private const int SECONDS = 300;

    public function store(PlanSequenceRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        set_time_limit(self::SECONDS);

        $shot->setRelation('project', $project);
        $turns = array_values((array) ($shot->plan_chat ?? []));
        $index = $request->integer('turn');
        $stored = $turns[$index]['sequence'] ?? null;

        if (! is_array($stored) || isset($stored['state'])) {
            throw ValidationException::withMessages(['turn' => __('This sequence was already answered.')]);
        }

        $accepted = $request->boolean('accept');
        $cast = (array) $request->validated('cast', $turns[$index]['cast'] ?? []);
        $elements = $project->elements()->get();
        $ticked = $elements->filter(fn(Element $element) => in_array($element->sqid, $cast, true))->values();
        $unticked = $elements->filter(fn(Element $element) => in_array($element->sqid, (array) ($turns[$index]['cast'] ?? []), true) && ! in_array($element->sqid, $cast, true));

        if ($accepted && $unticked->isNotEmpty()) {
            return $this->directAgain($project, $shot, $turns, $index, $elements, $unticked->pluck('name')->all());
        }

        $turns[$index]['sequence']['state'] = $accepted ? 'accepted' : 'declined';

        if ($accepted) {
            $turns[$index]['cast'] = $ticked->pluck('sqid')->values()->all();
        }

        $shot->forceFill(['plan_chat' => $turns])->save();

        if ($accepted) {
            SceneDirection::fromArray($stored, $project)->castWith($ticked->pluck('name')->all())->apply($project, $shot)
                ->filter(fn(Shot $part) => $part->status === ShotStatus::STORYLINE_READY)
                ->each(fn(Shot $part) => $part->setRelation('project', $project)->drawKeyframes());
        }

        return response()->json(['messages' => $shot->fresh()->plan_chat, 'reload' => $accepted]);
    }

    /**
     * Directs the story of the sequence again without the cast and sets the
     * director unticked, and shows the new sequence in its place.
     *
     * @param  list<array<string, mixed>>  $turns
     * @param  Collection<int, Element>  $elements
     * @param  list<string>  $unticked
     */
    private function directAgain(Project $project, Shot $shot, array $turns, int $index, Collection $elements, array $unticked): JsonResponse
    {
        $leftOut = array_values(array_unique([...(array) ($turns[$index]['left_out'] ?? []), ...$unticked]));
        [$direction, $undirected, $missing] = SequenceDirecting::direct($project, $shot, (string) ($turns[$index]['story'] ?? ''), $leftOut);

        if ($direction === null) {
            throw ValidationException::withMessages(['cast' => $undirected ?? __('This sequence cannot be directed again. Tell the story again in the chat.')]);
        }

        $names = array_map(fn(string $name) => mb_strtolower($name), $direction->elementNames());
        $turns[$index] = [
            ...$turns[$index],
            'sequence' => $direction->toArray(),
            'cast' => $elements->filter(fn(Element $element) => in_array(mb_strtolower($element->name), $names, true))->pluck('sqid')->values()->all(),
            'left_out' => $leftOut,
        ];
        $shot->forceFill(['plan_chat' => $turns])->save();

        // What the new sequence needs that the cast and sets do not have is offered to make.
        if ($missing !== []) {
            $new = GenerateStoryline::newElementsFrom($shot, [...(array) ($shot->storyline['new_elements'] ?? []), ...$missing]);
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'new_elements' => $new]);
        }

        return response()->json(['messages' => $shot->fresh()->plan_chat, 'reload' => $missing !== []]);
    }
}
