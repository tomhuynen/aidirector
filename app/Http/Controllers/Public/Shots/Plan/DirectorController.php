<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\Agents\PlanDirector;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Requests\Public\PlanDirectorRequest;
use App\Jobs\GenerateStoryline;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\ShotPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The conversation about a shot's plan: the director's message and the plan
 * director's reply. What they agree on is acted on straight away: cast and
 * sets are made or redrawn, a story is split into shots, and an agreed plan
 * is put into the shot and drawn.
 */
class DirectorController
{
    public function store(PlanDirectorRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! in_array($shot->status, [ShotStatus::DRAFT, ShotStatus::STORYLINE_READY], true) || $shot->keyframes()->exists()) {
            throw ValidationException::withMessages(['message' => __('The plan can be talked about until the keyframes are drawn.')]);
        }

        $shot->setRelation('project', $project);
        $elements = $project->elements()->get();
        $message = trim((string) $request->validated('message'));
        $conversation = array_values((array) ($shot->plan_chat ?? []));
        $director = new PlanDirector($shot, array_map(fn(array $turn) => ['role' => (string) $turn['role'], 'text' => (string) $turn['text']], $conversation));
        $prompt = $director->promptFor($message);
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $director->prompt($prompt, provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('The plan director is unavailable right now. Please try again.')], 503);
        }

        $shot->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'usage' => $response->usage->toArray(),
        ]);

        $data = $response->toArray();
        $stage = in_array($data['stage'] ?? null, PlanDirector::STAGES, true) ? $data['stage'] : null;
        $sqids = fn(array $names) => $elements->filter(fn(Element $element) => in_array(mb_strtolower($element->name), array_map(fn(mixed $name) => mb_strtolower((string) $name), $names), true))->pluck('sqid')->values()->all();

        // What it offers to make for the cast and sets; an earlier offer lapses once the conversation moves on.
        $offered = ($shot->storyline['new_elements'] ?? []) !== [];
        $new = GenerateStoryline::newElementsFrom($shot, $data['new_elements'] ?? null);
        $shot->updateStoredJson('storyline', fn(?array $storyline) => $new !== []
            ? [...($storyline ?? []), 'new_elements' => $new]
            : array_diff_key($storyline ?? [], ['new_elements' => true]));

        $made = $elements->filter(fn(Element $element) => in_array($element->sqid, (array) $request->validated('made', []), true))->pluck('sqid')->values()->all();
        $cast = $sqids((array) ($data['cast'] ?? []));
        $adjusted = $this->adjustElements($elements, $data['adjust_elements'] ?? null);
        $plan = ShotPlan::apply($shot, $data['proposal'] ?? null);

        $turns = [
            ...$conversation,
            array_filter(['role' => 'director', 'text' => $message, 'made' => $made]),
            array_filter(['role' => 'assistant', 'text' => trim((string) ($data['reply'] ?? '')), 'stage' => $stage, 'cast' => $cast, 'adjusted' => $adjusted, 'proposal' => $plan]),
        ];

        $shot->forceFill(['plan_chat' => $turns])->save();

        $added = $this->splitInto($project, $shot, $data['shots'] ?? null);

        // Agreed: drawn now, or as soon as the pictures or the setting it needs are there.
        if ($plan !== null) {
            $shot->drawKeyframes();
        }

        return response()->json(['messages' => $turns, 'reload' => $plan !== null || $new !== [] || $adjusted !== [] || $added > 0 || $offered]);
    }

    /**
     * Has the pictures of cast and sets redrawn with the change asked for in
     * the chat; they are shared by every shot. Returns their ids, shown with
     * the reply while they are drawn.
     *
     * @param  \Illuminate\Support\Collection<int, Element>  $elements
     * @return list<string>
     */
    private function adjustElements($elements, mixed $changes): array
    {
        return collect(is_array($changes) ? $changes : [])
            ->filter(fn(mixed $change) => is_array($change) && trim((string) ($change['change'] ?? '')) !== '')
            ->map(function (array $change) use ($elements) {
                $element = $elements->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower(trim((string) ($change['name'] ?? ''))));

                if ($element === null) {
                    return;
                }

                // Already being drawn: shown with its loader, the drawing under way is kept.
                if ($element->rendering) {
                    return $element->sqid;
                }

                $element->forceFill(['rendering' => true, 'render_error' => null])->save();
                UpdateElementImage::dispatch($element, trim((string) $change['change']));

                return $element->sqid;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The story told in several shots, as agreed: this shot becomes the first,
     * the others are added right after it with an empty plan and a chat that
     * starts from their idea. They share a group, so they show together in the
     * shot list and can be merged later. Returns how many shots were added.
     */
    private function splitInto(Project $project, Shot $shot, mixed $shots): int
    {
        $parts = collect(is_array($shots) ? $shots : [])
            ->filter(fn(mixed $part) => is_array($part) && trim((string) ($part['takeaway'] ?? '')) !== '')
            ->map(fn(array $part) => [
                'takeaway' => trim((string) $part['takeaway']),
                'kind' => ShotKind::tryFrom((string) ($part['kind'] ?? '')) ?? ShotKind::SCENE,
                'idea' => trim((string) ($part['idea'] ?? '')),
                'setting' => (string) ($part['setting'] ?? 'new_place'),
                'same_place_as' => (int) ($part['same_place_as'] ?? 0),
            ])
            ->values();

        if ($parts->count() < 2) {
            return 0;
        }

        DB::connection($shot->getConnectionName())->transaction(function () use ($project, $shot, $parts) {
            $group = $shot->group_key ?? (string) Str::ulid();
            $first = $parts->first();

            // The idea of each shot is kept as its notes, so every shot of the sequence sticks to its own part.
            $shot->forceFill([
                'group_key' => $group,
                'notes' => $first['idea'],
                'takeaway' => $first['takeaway'],
                'title' => Str::limit($first['takeaway'], 80),
                'kind' => $first['kind'],
            ])->save();

            $project->shots()->where('position', '>', $shot->position)->increment('position', $parts->count() - 1);

            $created = $parts->skip(1)->values()->map(fn(array $part, int $index) => $project->allShots()->create([
                'position' => $shot->position + $index + 1,
                'group_key' => $group,
                'title' => Str::limit($part['takeaway'], 80),
                'takeaway' => $part['takeaway'],
                'notes' => $part['idea'],
                'kind' => $part['kind'],
                'purpose_override' => $shot->purpose_override,
                'aspect_ratio_override' => $shot->aspect_ratio_override,
                'status' => ShotStatus::STORYLINE_READY,
                'chosen_storyline' => ['title' => Str::limit($part['takeaway'], 80), 'storyline' => ''],
                'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => []],
                'plan_chat' => [[
                    'role' => 'assistant',
                    'stage' => 'idea',
                    'text' => __('This shot is part of the sequence we planned: :idea Shall we go on from there?', ['idea' => $part['idea']]),
                ]],
            ]));

            // Where each shot plays, as agreed: from where the shot before ends, or in the place of an earlier shot.
            $sequence = [$shot, ...$created->all()];

            foreach ($parts->skip(1)->values() as $index => $part) {
                $from = match (true) {
                    $part['setting'] === 'continues' => ['shot_id' => $sequence[$index]->id, 'keyframe' => Shot::LAST_KEYFRAME],
                    $part['setting'] === 'same_place' && $part['same_place_as'] >= 1 && $part['same_place_as'] <= $index + 1 => ['shot_id' => $sequence[$part['same_place_as'] - 1]->id, 'keyframe' => 0],
                    default => null,
                };

                if ($from !== null) {
                    $sequence[$index + 1]->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'setting_from' => $from]);
                }
            }
        });

        return $parts->count() - 1;
    }
}
