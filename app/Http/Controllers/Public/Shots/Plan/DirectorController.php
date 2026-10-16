<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\Agents\PlanDirector;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Requests\Public\PlanDirectorRequest;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateVoiceOver;
use App\Jobs\TweakKeyframeImage;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeLayout;
use App\Support\Shots\KeyframeStaging;
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
        $director = new PlanDirector($shot, array_map(fn(array $turn) => ['role' => (string) $turn['role'], 'text' => $this->said($turn)], $conversation), $selected . $this->fieldsNow($keyframes, $request));
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

        // A new shot is added next to this one and never replaces this shot's plan.
        if (trim((string) ($data['add_shot']['idea'] ?? '')) !== '') {
            $data['proposal'] = null;
        }
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

        $idea = trim((string) ($data['idea'] ?? ''));

        if ($idea !== '') {
            $shot->forceFill(['idea' => $idea])->save();
        }

        $plan = ShotPlan::apply($shot, $data['proposal'] ?? null);
        $reply = trim((string) ($data['reply'] ?? ''));
        $proposed = $plan === null ? $this->propose($keyframes, $request, $data['changes'] ?? null) : null;
        $changes = $plan === null && $proposed === null && $keyframes->isNotEmpty() ? $this->change($shot, $keyframes, $request, $data['changes'] ?? null) : ['made' => [], 'waiting' => null];

        $addedShot = $this->addShot($project, $shot, $data['add_shot'] ?? null);
        $reply = $addedShot === null ? $reply : trim("{$reply} {$addedShot}");
        $voiceOver = $this->voiceOver($shot, $data['voice_over'] ?? null);
        $storylines = $this->storylines($data['storylines'] ?? null);
        $pickPlace = (bool) ($data['pick_place'] ?? false);
        // The places to choose from, such as every corridor; without names, every place.
        $places = $pickPlace ? array_values(array_intersect($sqids((array) ($data['places'] ?? [])), $elements->filter(fn(Element $element) => $element->type === ElementType::PLACE)->pluck('sqid')->all())) : [];
        // A message with buttons of its own is answered with those, never with a plain yes.
        $confirm = (bool) ($data['confirm'] ?? false) && $voiceOver === null && $proposed === null && $storylines === [] && ! $pickPlace && $cast === [];

        // Added to the conversation as stored now: what changed in it while the director waited, such as cast made meanwhile, stays.
        $shot->updateStoredJson('plan_chat', fn(?array $stored) => [
            ...array_values((array) $stored),
            array_filter(['role' => 'director', 'text' => $message, 'made' => $made, 'about' => $selected]),
            array_filter(['role' => 'assistant', 'text' => $changes['waiting'] === null ? $reply : "{$reply} {$changes['waiting']}", 'stage' => $stage, 'cast' => $cast, 'adjusted' => $adjusted, 'proposal' => $plan, 'changes' => $changes['made'], 'proposed' => $proposed, 'storylines' => $storylines, 'confirm' => $confirm, 'pickPlace' => $pickPlace, 'places' => $places, 'voiceOver' => $voiceOver]),
        ]);
        $turns = $shot->plan_chat;

        $added = $this->splitInto($project, $shot, $data['shots'] ?? null);
        $removed = $this->removeShots($project, $shot, $data['remove_shots'] ?? null);

        // Agreed: drawn now, or as soon as the pictures or the setting it needs are there.
        if ($plan !== null) {
            $shot->drawKeyframes();
        }

        return response()->json(['messages' => $turns, 'reload' => $plan !== null || $changes['made'] !== [] || $new !== [] || $adjusted !== [] || $added > 0 || $addedShot !== null || $removed !== [] || $offered]);
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
            $request->validated('target') === 'option' => [__('an option for keyframe 1, :title', ['title' => (string) $first?->title]), $first?->renders()->firstWhere('id', $request->integer('option'))],
            $keyframe !== null && $this->member($keyframe, $request) !== null => [__(':name in keyframe :n, :title, outlined in the image', ['name' => $this->member($keyframe, $request), 'n' => $keyframe->position, 'title' => $keyframe->title]), $keyframe->render()],
            $keyframe !== null => [__('keyframe :n, :title', ['n' => $keyframe->position, 'title' => $keyframe->title]), $keyframe->render()],
            default => [__('nothing: the images are still being drawn'), null],
        };
    }

    /**
     * What a turn said, with the storylines it suggested, so a pick by its
     * title is understood in the next message.
     *
     * @param  array<string, mixed>  $turn
     */
    private function said(array $turn): string
    {
        $options = collect((array) ($turn['storylines'] ?? []))
            ->map(fn(array $option, int $index) => ($index + 1) . ". \"{$option['title']}\" ({$option['kind']}): {$option['storyline']}" . (isset($option['needs']) ? " Needs: {$option['needs']}." : ''))
            ->join("\n");

        return (string) $turn['text'] . ($options === '' ? '' : "\nThe storylines suggested:\n{$options}");
    }

    /**
     * A new voice-over suggested in the chat, as written, with the length the
     * shot needs to speak it when that is longer than it is now; it is used
     * once the director accepts it.
     *
     * @return array{text: string, seconds?: int}|null
     */
    private function voiceOver(Shot $shot, mixed $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $seconds = Shot::clampSeconds((int) ceil(GenerateVoiceOver::words($text) / GenerateVoiceOver::WORDS_PER_SECOND));

        return $seconds > $shot->durationInSeconds() ? ['text' => $text, 'seconds' => $seconds] : ['text' => $text];
    }

    /**
     * The storylines suggested for the idea, to pick one from.
     *
     * @return list<array{title: string, kind: string, storyline: string, needs?: string}>
     */
    private function storylines(mixed $storylines): array
    {
        return collect(is_array($storylines) ? $storylines : [])
            ->filter(fn(mixed $option) => is_array($option) && trim((string) ($option['storyline'] ?? '')) !== '')
            ->map(function (array $option) {
                $needs = trim((string) ($option['needs'] ?? ''));

                return [
                    'title' => trim((string) ($option['title'] ?? '')),
                    'kind' => (ShotKind::tryFrom((string) ($option['kind'] ?? '')) ?? ShotKind::SCENE)->value,
                    'storyline' => trim((string) $option['storyline']),
                    ...($needs !== '' ? ['needs' => $needs] : []),
                ];
            })
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * The staging fields of the one of the cast selected as they are now, so a change starts from them; empty when none is.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     */
    private function fieldsNow(Collection $keyframes, PlanDirectorRequest $request): string
    {
        $keyframe = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));
        $name = $keyframe === null ? null : $this->member($keyframe, $request);

        if ($name === null) {
            return '';
        }

        $member = collect($keyframe->staged()->cast)->first(fn(array $member) => mb_strtolower($member['name']) === mb_strtolower($name));

        return $member === null ? '' : '; their fields now: ' . json_encode($member, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The one of the cast selected in a keyframe, by their name in its layout; null when none is.
     */
    private function member(Keyframe $keyframe, PlanDirectorRequest $request): ?string
    {
        $name = trim((string) $request->validated('member'));

        return $name === '' ? null : KeyframeLayout::fromArray($keyframe->layout)->member($name)['name'] ?? null;
    }

    /**
     * With one of the cast selected, the change to them is not made straight
     * away: it is proposed, and made once the director confirms it.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{keyframe: string, name: string, change: string, member: array<string, mixed>, fields: list<array{field: string, from: string, to: string}>}|null
     */
    private function propose(Collection $keyframes, PlanDirectorRequest $request, mixed $changes): ?array
    {
        $selected = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));
        $changes = collect(is_array($changes) ? $changes : [])->filter(fn(mixed $change) => is_array($change) && trim((string) ($change['change'] ?? '')) !== '');
        $selectedMember = $selected === null ? null : $this->member($selected, $request);

        // Someone selected: the change to their keyframe is about them.
        if ($selectedMember !== null) {
            [$keyframe, $name] = [$selected, $selectedMember];
            $change = $changes->first(fn(array $change) => in_array((int) ($change['keyframe'] ?? 0), [0, $selected->position], true));
        } else {
            // Otherwise a change that restages one of the cast is proposed the same way, so their fields follow it.
            $keyframe = $name = null;
            $change = $changes->first(function (array $change) use ($keyframes, $selected, &$keyframe, &$name) {
                $target = (int) ($change['keyframe'] ?? 0) > 0 ? $keyframes->firstWhere('position', (int) $change['keyframe']) : $selected;
                $found = $target?->render() === null ? null : (KeyframeLayout::fromArray($target->layout)->member(trim((string) ($change['member']['name'] ?? '')))['name'] ?? null);
                [$keyframe, $name] = $found === null ? [null, null] : [$target, $found];

                return $found !== null;
            });
        }

        if ($keyframe === null || $name === null || $change === null) {
            return null;
        }

        $member = trim((string) ($change['member']['name'] ?? '')) === '' ? [] : [...(array) $change['member'], 'name' => $name];

        return ['keyframe' => $keyframe->sqid, 'name' => $name, 'change' => trim((string) $change['change']), 'member' => $member, 'fields' => $this->changedFields($keyframe, $name, $member)];
    }

    /**
     * The staging fields a proposed change sets to another value, so the director sees what will change.
     *
     * @param  array<string, mixed>  $member
     * @return list<array{field: string, from: string, to: string}>
     */
    private function changedFields(Keyframe $keyframe, string $name, array $member): array
    {
        $after = KeyframeStaging::member($member);

        if ($after === null) {
            return [];
        }

        $before = collect($keyframe->staged()->cast)->first(fn(array $other) => mb_strtolower($other['name']) === mb_strtolower($name)) ?? [];

        return collect(['stands', 'faces', 'looks_at', 'holds', 'hand', 'doing'])
            ->filter(fn(string $field) => ($before[$field] ?? '') !== $after[$field])
            ->map(fn(string $field) => ['field' => $field, 'from' => (string) ($before[$field] ?? ''), 'to' => $after[$field]])
            ->values()
            ->all();
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

        // While choosing: the selected option for keyframe 1 is adjusted.
        if ($target === 'option' && $changes->every(fn(array $change) => $change['keyframe'] === 0)) {
            $change = $changes->first()['change'];
            $ready = $first !== null && ! $first->isBusy() && $shot->status === ShotStatus::FIRST_KEYFRAME_READY;
            $option = $first?->renders()->firstWhere('id', $request->integer('option'));

            if (! $ready || $option === null) {
                return ['made' => [], 'waiting' => __('(I can change it once the images are ready.)')];
            }

            $first->forceFill(['status' => KeyframeStatus::DRAWING, 'render_error' => null])->save();
            TweakKeyframeImage::dispatch($first, $change, $option->id, rewrite: true);

            return ['made' => [$change], 'waiting' => null];
        }

        $selected = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));
        $jobs = $changes
            ->map(fn(array $change) => [...$change, 'target' => $change['keyframe'] > 0 ? $keyframes->firstWhere('position', $change['keyframe']) : $selected])
            ->filter(fn(array $change) => $change['target'] !== null && $change['target']->render() !== null)
            ->sortBy(fn(array $change) => $change['target']->position)
            ->unique(fn(array $change) => $change['target']->id)
            ->values();

        if ($jobs->isEmpty() || $shot->status->isWorking() || $jobs->contains(fn(array $change) => $change['target']->isBusy())) {
            return ['made' => [], 'waiting' => __('(I can change it once it is drawn and nothing else is being drawn.)')];
        }

        $jobs->each(fn(array $change) => $change['target']->forceFill(['status' => KeyframeStatus::DRAWING, 'render_error' => null])->save());
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
            ->filter(fn(mixed $part) => is_array($part) && trim((string) ($part['idea'] ?? '')) !== '')
            ->map(fn(array $part) => [
                'title' => Str::limit(trim((string) ($part['title'] ?? '')), 80),
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

            // Every shot of the sequence keeps its own idea, so it sticks to its own part.
            $shot->forceFill([
                'group_key' => $group,
                'idea' => $first['idea'],
                'title' => $first['title'],
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
                            'title' => $part['title'],
                            'idea' => $part['idea'],
                            'kind' => $part['kind'],
                            'plan_chat' => $opening,
                        ])->save();
                    }

                    $sequence[] = $existing;

                    continue;
                }

                $last = end($sequence);
                $sequence[] = $this->insertAt($project, $last->position + 1, $group, $last, [
                    'title' => $part['title'],
                    'idea' => $part['idea'],
                    'kind' => $part['kind'],
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
     * Adds one shot right after or right before a shot of the film, after this
     * one unless another is named, in that shot's group, to plan in its own
     * chat. When the director asked for it, its keyframe 1 is an exact copy of
     * a keyframe of a shot, the last keyframe of the shot it follows unless
     * another is named. Returns what was done, for the reply, or null when
     * nothing was added.
     */
    private function addShot(Project $project, Shot $shot, mixed $proposed): ?string
    {
        $idea = is_array($proposed) ? trim((string) ($proposed['idea'] ?? '')) : '';

        if ($idea === '') {
            return null;
        }

        $shots = $project->shots()->get();
        $byCode = fn(mixed $code) => $shots->first(fn(Shot $other) => strcasecmp($other->code(), str_replace(' ', '', (string) $code)) === 0);
        $before = $byCode($proposed['before'] ?? '');
        $after = $before === null ? ($byCode($proposed['after'] ?? '') ?? $shot) : $shots->firstWhere('position', $before->position - 1);
        // The shot it joins: the one it goes before, or the one it follows.
        $neighbour = $before ?? $after;
        $startsFrom = is_array($proposed['starts_from'] ?? null) ? $proposed['starts_from'] : null;
        $source = $byCode($startsFrom['shot'] ?? '') ?? $after;
        $wanted = (int) ($startsFrom['keyframe'] ?? 0);
        $keyframe = match (true) {
            $startsFrom === null, $source === null => null,
            $wanted > 0 => $source->keyframes()->with(['media', 'elements'])->where('position', $wanted)->first(),
            default => $source->keyframes()->with(['media', 'elements'])->reorder('position', 'desc')->first(),
        };
        $image = $keyframe?->render();
        $kind = ShotKind::tryFrom((string) ($proposed['kind'] ?? '')) ?? ShotKind::SCENE;
        // A shot that draws every still on its own, such as a montage, cannot go on from a copy.
        $copies = $image !== null && ! (new Shot())->forceFill(['kind' => $kind])->drawsStandalone();

        $added = DB::connection($shot->getConnectionName())->transaction(function () use ($project, $neighbour, $before, $source, $keyframe, $image, $kind, $copies, $idea, $proposed) {
            $group = $neighbour->group_key ?? (string) Str::ulid();
            $neighbour->forceFill(['group_key' => $group])->save();

            $plan = $copies ? [[...($source->storylineKeyframes()[$keyframe->position - 1] ?? [
                'title' => $keyframe->title,
                'description' => $keyframe->description,
                'staging' => $keyframe->staging,
                'spatial' => $keyframe->spatial,
                'elements' => $keyframe->elements->pluck('name')->all(),
            ]), 'copied' => true]] : [];

            $added = $this->insertAt($project, $before === null ? $neighbour->position + 1 : $before->position, $group, $neighbour, [
                'title' => Str::limit(trim((string) ($proposed['title'] ?? '')), 80),
                'idea' => $idea,
                'kind' => $kind,
                'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => $plan, ...($copies ? ['opening_copy' => true] : [])],
            ]);

            if ($copies) {
                // The image and the staging carry over; how long it lasts belongs to the shot it came from.
                $copy = $keyframe->replicate(['render_id', 'prompt', 'depends_on_id', 'render_error', 'seconds']);
                $copy->forceFill(['shot_id' => $added->id, 'position' => 1, 'status' => KeyframeStatus::READY, 'draw_version' => 0])->save();
                $copy->elements()->sync($keyframe->elements->modelKeys());
                $copy->forceFill(['render_id' => $image->copy($copy, Keyframe::RENDERS)->id])->save();
            }

            return $added;
        });

        // Codes as they are now: a shot added before another moves it and the shots after it one place on.
        $where = $before === null ? __('after :shot', ['shot' => $neighbour->refresh()->code()]) : __('before :shot', ['shot' => $neighbour->refresh()->code()]);
        $from = $copies ? __('keyframe :n of :shot', ['n' => $keyframe->position, 'shot' => $source->refresh()->code()]) : '';

        $added->forceFill(['plan_chat' => [[
            'role' => 'assistant',
            'stage' => 'idea',
            'text' => $copies
                ? __('This shot comes :where and starts from :from as it is: :idea Shall we go on from there?', ['where' => $where, 'from' => $from, 'idea' => $idea])
                : __('This shot comes :where: :idea Shall we go on from there?', ['where' => $where, 'idea' => $idea]),
        ]]])->save();

        return match (true) {
            $copies => __(':shot is added :where, in the same group; its keyframe 1 is a copy of :from. Plan it in its own chat.', ['shot' => $added->code(), 'where' => $where, 'from' => $from]),
            $startsFrom === null => __(':shot is added :where, in the same group. Plan it in its own chat.', ['shot' => $added->code(), 'where' => $where]),
            $image === null => __(':shot is added :where, in the same group. There is no drawn keyframe to copy yet, so its keyframe 1 is drawn from the plan. Plan it in its own chat.', ['shot' => $added->code(), 'where' => $where]),
            default => __(':shot is added :where, in the same group. A :kind draws every still on its own, so it does not start from a copy. Plan it in its own chat.', ['shot' => $added->code(), 'where' => $where, 'kind' => $kind->value]),
        };
    }

    /**
     * A new shot at a position of the film, in a group, with an empty plan;
     * the shot from that position on move one place on.
     *
     * @param  array<model-property<Shot>, mixed>  $attributes
     */
    private function insertAt(Project $project, int $position, string $group, Shot $like, array $attributes): Shot
    {
        $project->shots()->where('position', '>=', $position)->increment('position');

        return $project->allShots()->create([
            'position' => $position,
            'group_key' => $group,
            'purpose_override' => $like->purpose_override,
            'aspect_ratio_override' => $like->aspect_ratio_override,
            'status' => ShotStatus::STORYLINE_READY,
            'chosen_storyline' => ['storyline' => ''],
            'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => []],
            ...$attributes,
        ]);
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
