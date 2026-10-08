<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\Agents\PlanDirector;
use App\Ai\KeyframePainter;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Requests\Public\PlanDirectorRequest;
use App\Jobs\AdjustPlateOption;
use App\Jobs\GenerateStoryline;
use App\Jobs\TweakKeyframeImage;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\ShotPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The conversation about a shot, from its plan to its drawn images: the
 * director's message and the plan director's reply. What they agree on is
 * acted on straight away: cast and sets are made or redrawn, a story is split
 * into shots, an agreed plan is put into the shot and drawn, and changes to
 * the drawn images are made.
 */
class DirectorController
{
    public function store(PlanDirectorRequest $request, Project $project, Shot $shot, KeyframePainter $painter): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $elements = $project->elements()->get();
        $keyframes = $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot);
        $message = trim((string) $request->validated('message'));
        $conversation = array_values((array) ($shot->plan_chat ?? []));
        // Once the images are drawn, the conversation is about the one the director selected.
        [$selected, $image] = $keyframes->isEmpty() ? ['', null] : $this->selected($shot, $keyframes, $request);
        $director = new PlanDirector($shot, array_map(fn(array $turn) => ['role' => (string) $turn['role'], 'text' => (string) $turn['text']], $conversation), $selected);
        $prompt = $director->promptFor($message);
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $director->prompt($prompt, attachments: $image !== null ? [$painter->referenceFor($image)] : [], provider: 'openrouter', model: $model);
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

        // A new plan for a drawn shot: what was drawn for the old one goes, and the new plan is drawn.
        if ($keyframes->isNotEmpty() && ShotPlan::isPlan($data['proposal'] ?? null)) {
            $shot->startPlanOver();
            $keyframes = collect();
        }

        $plan = ShotPlan::apply($shot, $data['proposal'] ?? null);
        $reply = trim((string) ($data['reply'] ?? ''));
        $changes = $plan === null && $keyframes->isNotEmpty() ? $this->change($shot, $keyframes, $request, $data['changes'] ?? null) : ['made' => [], 'waiting' => null];

        $turns = [
            ...$conversation,
            array_filter(['role' => 'director', 'text' => $message, 'made' => $made, 'about' => $selected]),
            array_filter(['role' => 'assistant', 'text' => $changes['waiting'] === null ? $reply : "{$reply} {$changes['waiting']}", 'stage' => $stage, 'cast' => $cast, 'adjusted' => $adjusted, 'proposal' => $plan, 'changes' => $changes['made']]),
        ];

        $shot->forceFill(['plan_chat' => $turns])->save();

        $added = $this->splitInto($project, $shot, $data['shots'] ?? null);
        $removed = $this->removeShots($project, $shot, $data['remove_shots'] ?? null);

        // Agreed: drawn now, or as soon as the pictures or the setting it needs are there.
        if ($plan !== null) {
            $shot->drawKeyframes();
        }

        return response()->json(['messages' => $turns, 'reload' => $plan !== null || $changes['made'] !== [] || $new !== [] || $adjusted !== [] || $added > 0 || $removed !== [] || $offered]);
    }

    /**
     * What the director has selected among the drawn images, in words, and its image.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{0: string, 1: Media|null}
     */
    private function selected(Shot $shot, Collection $keyframes, PlanDirectorRequest $request): array
    {
        $first = $keyframes->firstWhere('position', 1);
        $keyframe = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));

        return match (true) {
            $request->validated('target') === 'place' => [__('a place to choose from, for the whole shot'), $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $request->integer('option'))],
            $request->validated('target') === 'option' => [__('an option for keyframe 1, :title', ['title' => (string) $first?->title]), $first?->renders()->firstWhere('id', $request->integer('option'))],
            $keyframe !== null => [__('keyframe :n, :title', ['n' => $keyframe->position, 'title' => $keyframe->title]), $keyframe->render()],
            default => [__('nothing: the images are still being drawn'), null],
        };
    }

    /**
     * Makes the changes the director asked for on the drawn images. Changes to
     * keyframes are made one after the other, in their order, so each one is
     * drawn with the corrected keyframe before it. Returns what was changed,
     * and why nothing could be changed right now.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{made: list<string>, waiting: string|null}
     */
    private function change(Shot $shot, Collection $keyframes, PlanDirectorRequest $request, mixed $changes): array
    {
        $changes = collect(is_array($changes) ? $changes : [])
            ->filter(fn(mixed $change) => is_array($change) && trim((string) ($change['change'] ?? '')) !== '')
            ->map(fn(array $change) => ['keyframe' => max(0, (int) ($change['keyframe'] ?? 0)), 'change' => trim((string) $change['change'])])
            ->values();

        if ($changes->isEmpty()) {
            return ['made' => [], 'waiting' => null];
        }

        $first = $keyframes->firstWhere('position', 1);
        $target = $request->validated('target');

        // While choosing: the selected place or option for keyframe 1 is adjusted.
        if (in_array($target, ['place', 'option'], true) && $changes->every(fn(array $change) => $change['keyframe'] === 0)) {
            $change = $changes->first()['change'];
            $ready = $first !== null && ! $first->rendering && $shot->status === ShotStatus::FIRST_KEYFRAME_READY;
            $place = $target === 'place' ? $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $request->integer('option')) : null;
            $option = $target === 'option' ? $first?->renders()->firstWhere('id', $request->integer('option')) : null;

            if (! $ready || ($place === null && $option === null) || ($place !== null && $shot->hasChosenPlate())) {
                return ['made' => [], 'waiting' => __('(I can change it once the images are ready.)')];
            }

            $first->forceFill(['rendering' => true, 'render_error' => null])->save();
            $place !== null
                ? AdjustPlateOption::dispatch($shot, $place->id, $change)
                : TweakKeyframeImage::dispatch($first, $change, $option->id, rewrite: true);

            return ['made' => [$change], 'waiting' => null];
        }

        $selected = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));
        $jobs = $changes
            ->map(fn(array $change) => [...$change, 'target' => $change['keyframe'] > 0 ? $keyframes->firstWhere('position', $change['keyframe']) : $selected])
            ->filter(fn(array $change) => $change['target'] !== null && $change['target']->render() !== null)
            ->sortBy(fn(array $change) => $change['target']->position)
            ->unique(fn(array $change) => $change['target']->id)
            ->values();

        if ($jobs->isEmpty() || $shot->status->isWorking() || $jobs->contains(fn(array $change) => $change['target']->rendering)) {
            return ['made' => [], 'waiting' => __('(I can change it once it is drawn and nothing else is being drawn.)')];
        }

        $jobs->each(fn(array $change) => $change['target']->forceFill(['rendering' => true, 'render_error' => null])->save());
        Bus::chain($jobs->map(fn(array $change) => new TweakKeyframeImage($change['target'], $change['change'], rewrite: true))->all())
            ->onQueue(Config::get('pipeline.queue'))
            ->dispatch();

        return ['made' => $jobs->map(fn(array $change) => __('Keyframe :n: :change', ['n' => $change['target']->position, 'change' => $change['change']]))->all(), 'waiting' => null];
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
     * the others follow right after it with an empty plan and a chat that
     * starts from their idea. They share a group, so they show together in the
     * shot list and can be merged later. Returns how many shots the sequence
     * has after this one.
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
            // Agreed again on a sequence that already exists: the shots after this one are updated in order, only extra ones are added.
            $following = $shot->group_key === null
                ? collect()
                : $project->shots()->where('group_key', $shot->group_key)->where('position', '>', $shot->position)->get()->values();
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

            $sequence = [$shot];

            foreach ($parts->skip(1)->values() as $index => $part) {
                $opening = [[
                    'role' => 'assistant',
                    'stage' => 'idea',
                    'text' => __('This shot is part of the sequence we planned: :idea Shall we go on from there?', ['idea' => $part['idea']]),
                ]];
                $existing = $following->get($index);

                if ($existing !== null) {
                    // A shot that is drawn or talked about already keeps what it is.
                    if ($existing->isUntouchedInSequence()) {
                        $existing->forceFill([
                            'title' => Str::limit($part['takeaway'], 80),
                            'takeaway' => $part['takeaway'],
                            'notes' => $part['idea'],
                            'kind' => $part['kind'],
                            'plan_chat' => $opening,
                        ])->save();
                    }

                    $sequence[] = $existing;

                    continue;
                }

                $after = end($sequence)->position;
                $project->shots()->where('position', '>', $after)->increment('position');

                $sequence[] = $project->allShots()->create([
                    'position' => $after + 1,
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
                    'plan_chat' => $opening,
                ]);
            }

            // Where each shot plays, as agreed: from where the shot before ends, or in the place of an earlier shot.
            foreach ($parts->skip(1)->values() as $index => $part) {
                $from = match (true) {
                    $part['setting'] === 'continues' => ['shot_id' => $sequence[$index]->id, 'keyframe' => Shot::LAST_KEYFRAME],
                    $part['setting'] === 'same_place' && $part['same_place_as'] >= 1 && $part['same_place_as'] <= $index + 1 => ['shot_id' => $sequence[$part['same_place_as'] - 1]->id, 'keyframe' => 0],
                    default => null,
                };

                if ($from !== null && $sequence[$index + 1]->isUntouchedInSequence()) {
                    $sequence[$index + 1]->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'setting_from' => $from]);
                }
            }
        });

        return $parts->count() - 1;
    }

    /**
     * Removes shots of this sequence the director agreed to drop, such as a
     * duplicate. Only shots that are still untouched go: planned together with
     * this one, nothing drawn and no conversation of their own; never this
     * shot. Returns the codes of the shots removed.
     *
     * @return list<string>
     */
    private function removeShots(Project $project, Shot $shot, mixed $codes): array
    {
        $wanted = array_map(fn(mixed $code) => mb_strtoupper(str_replace(' ', '', (string) $code)), is_array($codes) ? $codes : []);

        if ($wanted === [] || $shot->group_key === null) {
            return [];
        }

        $removed = $project->shots()->where('group_key', $shot->group_key)->whereKeyNot($shot->getKey())->get()
            ->filter(fn(Shot $other) => in_array($other->code(), $wanted, true) && $other->isUntouchedInSequence())
            ->each(fn(Shot $other) => $other->delete())
            ->map(fn(Shot $other) => $other->code())
            ->values()
            ->all();

        if ($removed !== []) {
            $project->shots()->orderBy('position')->get()->each(fn(Shot $sibling, int $index) => $sibling->update(['position' => $index + 1]));
        }

        return $removed;
    }
}
