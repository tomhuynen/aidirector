<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\Agents\PlanDirector;
use App\Ai\KeyframePainter;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Requests\Public\PlanDirectorRequest;
use App\Jobs\ChangePlace;
use App\Jobs\GenerateStoryline;
use App\Jobs\TweakKeyframeImage;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\ReviewerVerdict;
use App\Models\Shot;
use App\Support\Decisions\FindingsReport;
use App\Support\Decisions\ShotIssues;
use App\Support\Shots\FirstKeyframeChoice;
use App\Support\Shots\ShotPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
    /** The most places or options attached for the plan director to see while the director chooses. */
    private const int MAX_OPTIONS_SHOWN = 8;

    public function store(PlanDirectorRequest $request, Project $project, Shot $shot, KeyframePainter $painter, FirstKeyframeChoice $choice, FindingsReport $report, ShotIssues $issues): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $elements = $project->elements()->get();
        $keyframes = $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot);
        $message = trim((string) $request->validated('message'));
        $conversation = array_values((array) ($shot->plan_chat ?? []));
        // Before the keyframes are drawn, the places or options waiting for a choice are attached in order; after, the selected image.
        $waiting = $choice->waiting($shot);
        [$selected, $image] = $keyframes->isEmpty() || $waiting !== null ? ['', null] : $this->selected($keyframes, $request);
        $images = $waiting !== null ? $waiting['options']->take(self::MAX_OPTIONS_SHOWN)->values()->all() : array_filter([$image]);
        // What the checks found and was reported in the chat, waiting for the director's answer.
        $findings = $keyframes->isEmpty() ? null : $report->waiting($shot, $keyframes);
        $director = new PlanDirector(
            $shot,
            array_map(fn(array $turn) => ['role' => (string) $turn['role'], 'text' => (string) $turn['text']], $conversation),
            $selected,
            $waiting !== null ? ['step' => $waiting['step'], 'count' => count($images)] : null,
            $findings['findings'] ?? [],
        );
        $prompt = $director->promptFor($message);
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $director->prompt($prompt, attachments: array_map(fn(Media $media) => $painter->referenceFor($media), array_values($images)), provider: 'openrouter', model: $model);
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

        // The same plan again for a drawn shot draws nothing new: what is drawn stays.
        $proposal = $keyframes->isNotEmpty() && ShotPlan::isCurrent($shot, $data['proposal'] ?? null) ? null : $data['proposal'] ?? null;

        // A new plan for a drawn shot: what was drawn for the old one goes, and the new plan is drawn.
        if ($keyframes->isNotEmpty() && ShotPlan::isPlan($proposal)) {
            $shot->startPlanOver();
            $keyframes = collect();
        }

        $plan = ShotPlan::apply($shot, $proposal);
        $reply = trim((string) ($data['reply'] ?? ''));
        $chosen = match (true) {
            $plan !== null => ['made' => [], 'waiting' => null],
            $waiting !== null => $this->choose($shot, $waiting, $choice, $data['choice'] ?? null),
            default => $this->backToPlaces($shot, $keyframes, $data['choice'] ?? null),
        };
        $changes = match (true) {
            $plan !== null || $keyframes->isEmpty() || $chosen['made'] !== [] => ['made' => [], 'waiting' => null],
            $waiting !== null && $waiting['step'] !== FirstKeyframeChoice::CONFIRM => $this->adjustOption($shot, $waiting, $choice, $data['changes'] ?? null),
            default => $this->change($shot, $keyframes, $request, $data['changes'] ?? null, $painter),
        };
        $judged = $plan === null && $findings !== null ? $this->judge($shot, $keyframes, $issues, $findings, $data['verdicts'] ?? null, $conversation) : ['made' => [], 'waiting' => null];
        $changes = ['made' => [...$chosen['made'], ...$judged['made'], ...$changes['made']], 'waiting' => $chosen['waiting'] ?? $judged['waiting'] ?? $changes['waiting']];

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
    private function selected(Collection $keyframes, PlanDirectorRequest $request): array
    {
        $keyframe = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));

        return $keyframe !== null
            ? [__('keyframe :n, :title', ['n' => $keyframe->position, 'title' => $keyframe->title]), $keyframe->render()]
            : [__('nothing: the images are still being drawn'), null];
    }

    /**
     * Makes the choice the director said in the chat: a place or an option
     * for keyframe 1 by its number, keyframe 1 confirmed, more to choose
     * from, or back to the places. Returns what was done, and why nothing
     * could be done right now.
     *
     * @param  array{step: string, options: Collection<int, Media>}  $waiting
     * @return array{made: list<string>, waiting: string|null}
     */
    private function choose(Shot $shot, array $waiting, FirstKeyframeChoice $choice, mixed $said): array
    {
        $action = is_array($said) ? (string) ($said['action'] ?? 'none') : 'none';
        $number = is_array($said) ? max(1, (int) ($said['option'] ?? 1)) : 1;
        $option = $waiting['options']->get($number - 1);

        // What to do, and how it shows in the conversation.
        [$act, $made] = match (true) {
            $action === 'choose' && $option !== null && $waiting['step'] === FirstKeyframeChoice::PLACES => [fn() => $choice->choosePlace($shot, $option), __('Place :n chosen', ['n' => $number])],
            $action === 'choose' && $option !== null && $waiting['step'] === FirstKeyframeChoice::OPTIONS => [fn() => $choice->useFirst($shot, $option), __('Option :n used for keyframe 1', ['n' => $number])],
            $action === 'choose' && $option !== null => [fn() => $choice->useFirst($shot, $option), __('Keyframe 1 confirmed')],
            $action === 'more' => [fn() => $choice->more($shot), $waiting['step'] === FirstKeyframeChoice::CONFIRM ? __('Keyframe 1 drawn again') : __('More to choose from')],
            $action === 'another_place' && $waiting['step'] === FirstKeyframeChoice::CONFIRM => [fn() => $choice->anotherPlace($shot), __('Back to the places')],
            default => [null, null],
        };

        try {
            if ($act !== null) {
                $act();
            }
        } catch (ValidationException $exception) {
            return ['made' => [], 'waiting' => '(' . collect($exception->errors())->flatten()->first() . ')'];
        }

        return ['made' => $made !== null ? [$made] : [], 'waiting' => null];
    }

    /**
     * The director's answer to the findings reported in the chat: the ones to
     * fix are redrawn with what was found, the ones to ignore are taken off
     * the list; both are kept as the director's verdict on the reviewer.
     * Each answered finding is marked in the conversation.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  array{turn: int, findings: list<array{n: int, keyframes: list<int>, note: string, issues: list<string>}>}  $findings
     * @param  list<array<string, mixed>>  $conversation
     * @return array{made: list<string>, waiting: string|null}
     */
    private function judge(Shot $shot, Collection $keyframes, ShotIssues $issues, array $findings, mixed $verdicts, array &$conversation): array
    {
        $byNumber = collect($findings['findings'])->keyBy('n');
        $said = collect(is_array($verdicts) ? $verdicts : [])
            ->filter(fn(mixed $verdict) => is_array($verdict) && $byNumber->has((int) ($verdict['finding'] ?? 0)) && in_array($verdict['verdict'] ?? null, ['fix', 'ignore'], true))
            ->mapWithKeys(fn(array $verdict) => [(int) $verdict['finding'] => $verdict['verdict']]);

        if ($said->isEmpty()) {
            return ['made' => [], 'waiting' => null];
        }

        $toFix = $said->filter(fn(string $verdict) => $verdict === 'fix')->keys();
        $toIgnore = $said->filter(fn(string $verdict) => $verdict === 'ignore')->keys();

        $issues->resolveIds($shot, $keyframes, $toIgnore->flatMap(fn(int $n) => $byNumber[$n]['issues'])->values()->all(), ReviewerVerdict::DISMISSED, ReviewerVerdict::VIA_CHAT);
        $fixed = $issues->fixInChat($shot, $keyframes, $toFix->flatMap(fn(int $n) => $byNumber[$n]['issues'])->values()->all());

        // A finding on a keyframe that is still being drawn keeps waiting; the others are answered.
        $answered = $said->filter(fn(string $verdict, int $n) => $verdict === 'ignore' || array_intersect($byNumber[$n]['keyframes'], $fixed['busy']) === []);
        $conversation[$findings['turn']]['findings'] = collect($conversation[$findings['turn']]['findings'])
            ->map(fn(array $finding) => $answered->has($finding['n']) ? [...$finding, 'verdict' => $answered[$finding['n']]] : $finding)
            ->all();

        return [
            'made' => array_values(array_filter([
                $fixed['fixed'] === [] ? null : trans_choice('Fixing keyframe :list|Fixing keyframes :list', count($fixed['fixed']), ['list' => collect($fixed['fixed'])->join(', ', ' and ')]),
                $toIgnore->isEmpty() ? null : trans_choice('Left as it is: finding :list|Left as it is: findings :list', $toIgnore->count(), ['list' => $toIgnore->join(', ', ' and ')]),
            ])),
            'waiting' => $fixed['busy'] === [] ? null : trans_choice('(Keyframe :list is still being drawn; I can fix it once it is ready.)|(Keyframes :list are still being drawn; I can fix them once they are ready.)', count($fixed['busy']), ['list' => collect($fixed['busy'])->join(', ', ' and ')]),
        ];
    }

    /**
     * Once the keyframes are drawn, another place for the same plan: the
     * drawn keyframes go, and new places are drawn to choose from.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{made: list<string>, waiting: string|null}
     */
    private function backToPlaces(Shot $shot, Collection $keyframes, mixed $said): array
    {
        if (! is_array($said) || ($said['action'] ?? null) !== 'another_place' || $keyframes->isEmpty()) {
            return ['made' => [], 'waiting' => null];
        }

        if ($shot->status->isWorking()) {
            return ['made' => [], 'waiting' => __('(I can go back to the places once nothing is being drawn.)')];
        }

        $shot->startPlanOver();
        $shot->drawKeyframes();

        return ['made' => [__('Back to the places')], 'waiting' => null];
    }

    /**
     * Adjusts a place or an option for keyframe 1 by its number, before it is
     * chosen; the result is added as a new one.
     *
     * @param  array{step: string, options: Collection<int, Media>}  $waiting
     * @return array{made: list<string>, waiting: string|null}
     */
    private function adjustOption(Shot $shot, array $waiting, FirstKeyframeChoice $choice, mixed $changes): array
    {
        // With one option, such as keyframe 1 of a close-up, a change to keyframe 1 is a change to that option.
        $single = $waiting['options']->count() === 1;
        $change = collect(is_array($changes) ? $changes : [])
            ->first(fn(mixed $change) => is_array($change) && trim((string) ($change['change'] ?? '')) !== '' && ((int) ($change['option'] ?? 0) > 0 || $single));

        if ($change === null) {
            return ['made' => [], 'waiting' => null];
        }

        $number = max(1, (int) $change['option']);
        $option = $waiting['options']->get($number - 1);

        if ($option === null) {
            return ['made' => [], 'waiting' => __('(There is no number :n to change.)', ['n' => $number])];
        }

        try {
            $choice->adjust($shot, $option, trim((string) $change['change']));
        } catch (ValidationException $exception) {
            return ['made' => [], 'waiting' => '(' . collect($exception->errors())->flatten()->first() . ')'];
        }

        return ['made' => [($waiting['step'] === FirstKeyframeChoice::PLACES ? __('Place :n', ['n' => $number]) : __('Option :n', ['n' => $number])) . ': ' . trim((string) $change['change'])], 'waiting' => null];
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
    private function change(Shot $shot, Collection $keyframes, PlanDirectorRequest $request, mixed $changes, KeyframePainter $painter): array
    {
        $changes = collect(is_array($changes) ? $changes : [])
            ->filter(fn(mixed $change) => is_array($change) && trim((string) ($change['change'] ?? '')) !== '')
            ->map(fn(array $change) => [
                'keyframe' => max(0, (int) ($change['keyframe'] ?? 0)),
                'change' => trim((string) $change['change']),
                'place' => (bool) ($change['place'] ?? false),
                'part' => trim((string) ($change['part'] ?? '')),
            ])
            ->values();

        if ($changes->isEmpty()) {
            return ['made' => [], 'waiting' => null];
        }

        $selected = $keyframes->firstWhere('sqid', (string) $request->validated('keyframe'));

        // A change to the place itself is made in the empty place; drawn on a keyframe it would be covered by the place again.
        $onPlace = $painter->placeAt($shot, $keyframes, 1) !== null;
        [$place, $changes] = $changes->partition(fn(array $change) => $change['place'] && $onPlace);
        $placed = $this->changePlace($shot, $keyframes, $selected, $place->values());

        if ($changes->isEmpty()) {
            return $placed;
        }

        $targets = $changes
            ->map(fn(array $change) => [...$change, 'target' => $change['keyframe'] > 0 ? $keyframes->firstWhere('position', $change['keyframe']) : $selected])
            ->filter(fn(array $change) => $change['target'] !== null)
            ->sortBy(fn(array $change) => $change['target']->position)
            ->unique(fn(array $change) => $change['target']->id)
            ->values();

        // Only a finished keyframe is changed; one still being drawn, changed or checked waits, so two drawings never overwrite each other.
        [$jobs, $busy] = $targets->partition(fn(array $change) => $change['target']->render() !== null && ! $change['target']->rendering);
        $waiting = $busy->isEmpty() ? null : trans_choice(
            '(Keyframe :list is still being drawn; I can change it once it is ready.)|(Keyframes :list are still being drawn; I can change them once they are ready.)',
            $busy->count(),
            ['list' => $busy->map(fn(array $change) => $change['target']->position)->join(', ', ' and ')],
        );

        if ($jobs->isEmpty()) {
            return ['made' => [], 'waiting' => $waiting];
        }

        $jobs = $jobs->values();
        $jobs->each(fn(array $change) => $change['target']->forceFill(['rendering' => true, 'render_error' => null])->save());
        Bus::chain($jobs->map(fn(array $change) => new TweakKeyframeImage($change['target'], $change['change'], rewrite: true))->all())
            ->onQueue(Config::get('pipeline.queue'))
            ->dispatch();

        return [
            'made' => [...$placed['made'], ...$jobs->map(fn(array $change) => __('Keyframe :n: :change', ['n' => $change['target']->position, 'change' => $change['change']]))->all()],
            'waiting' => $placed['waiting'] ?? $waiting,
        ];
    }

    /**
     * Makes changes to the empty place, each from a keyframe on, and puts the
     * keyframes from the first of them onto the changed place. Waits when one
     * of those keyframes is still being drawn.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  Collection<int, array{keyframe: int, change: string, place: bool, part: string}>  $changes
     * @return array{made: list<string>, waiting: string|null}
     */
    private function changePlace(Shot $shot, Collection $keyframes, ?Keyframe $selected, Collection $changes): array
    {
        if ($changes->isEmpty()) {
            return ['made' => [], 'waiting' => null];
        }

        $changes = $changes->map(fn(array $change) => [...$change, 'from' => $change['keyframe'] > 0 ? $change['keyframe'] : (int) ($selected->position ?? 1)]);
        $from = (int) $changes->min('from');

        if ($keyframes->where('position', '>=', $from)->contains(fn(Keyframe $keyframe) => $keyframe->rendering || $keyframe->render() === null)) {
            return ['made' => [], 'waiting' => __('(I can change the place once the keyframes from :n on are ready.)', ['n' => $from])];
        }

        $changes->each(fn(array $change) => $shot->addPlaceEdit($change['from'], $change['change'], $change['part']));
        $shot->keyframes()->where('position', '>=', $from)->update(['rendering' => true, 'render_error' => null]);
        ChangePlace::dispatch($shot, $from);

        return ['made' => $changes->map(fn(array $change) => __('The place, from keyframe :n on: :change', ['n' => $change['from'], 'change' => $change['change']]))->values()->all(), 'waiting' => null];
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
