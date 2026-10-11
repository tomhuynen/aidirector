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
use App\Jobs\GenerateVoiceOver;
use App\Jobs\TweakKeyframeImage;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\ReviewerVerdict;
use App\Models\Shot;
use App\Support\Findings\FindingsReport;
use App\Support\Findings\ShotIssues;
use App\Support\Shots\DrawnPlan;
use App\Support\Shots\FirstKeyframeChoice;
use App\Support\Shots\SequencePlaces;
use App\Support\Shots\ShotPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\RateLimitedException;
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

    /** How often the plan director is asked when the AI provider answers with a rate limit. */
    private const int RATE_LIMIT_ATTEMPTS = 3;

    /** How long to wait before asking again after a rate limit, times the attempt, in milliseconds. */
    private const int RATE_LIMIT_WAIT_MS = 2000;

    public function store(PlanDirectorRequest $request, Project $project, Shot $shot, KeyframePainter $painter, FirstKeyframeChoice $choice, FindingsReport $report, ShotIssues $issues, SequencePlaces $sequencePlaces): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $elements = $project->elements()->get();
        $keyframes = $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot);
        // Going on by itself, such as once the places are chosen by clicking: an instruction, not something the director said.
        $continuing = $request->boolean('continue');
        $message = $continuing
            ? __('(Every place of the sequence is chosen now. Go on with this shot from where the conversation is: take the next step in this answer.)')
            : trim((string) $request->validated('message'));
        $conversation = array_values((array) ($shot->plan_chat ?? []));
        // Before the keyframes are drawn, the places or options waiting for a choice are attached in order; after, the selected image.
        // The places of a sequence come first: they are chosen in the chat of its first shot before anything else.
        $waiting = $sequencePlaces->waiting($shot) ?? $choice->waiting($shot);
        [$selected, $image] = $keyframes->isEmpty() || $waiting !== null ? ['', null] : $this->selected($keyframes, $request);
        $images = $waiting !== null ? $waiting['options']->take(self::MAX_OPTIONS_SHOWN)->values()->all() : array_filter([$image]);
        // What the checks found and was reported in the chat, waiting for the director's answer.
        $findings = $keyframes->isEmpty() ? null : $report->waiting($shot, $keyframes);
        $director = new PlanDirector(
            $shot,
            array_map(fn(array $turn) => ['role' => (string) $turn['role'], 'text' => (string) $turn['text']], $conversation),
            $selected,
            $waiting !== null ? ['step' => $waiting['step'], 'count' => count($images), 'place' => isset($waiting['place']) ? "{$waiting['place']['name']}, for " . implode(', ', $waiting['place']['shots']) : null] : null,
            $findings['findings'] ?? [],
        );
        $prompt = $director->promptFor($message);
        $model = (string) Config::get('pipeline.models.text');
        // A rate limit passes within seconds: asked again a little later, before the director sees an error.
        $ask = function (string $prompt, array $attachments) use ($director, $model): StructuredAgentResponse {
            /** @var StructuredAgentResponse $response an agent with a schema answers in that structure */
            $response = retry(self::RATE_LIMIT_ATTEMPTS, fn() => $director->prompt($prompt, attachments: $attachments, provider: 'openrouter', model: $model), fn(int $attempt) => $attempt * self::RATE_LIMIT_WAIT_MS, fn(Throwable $exception) => $exception instanceof RateLimitedException);

            return $response;
        };
        $log = fn(StructuredAgentResponse $response, string $prompt) => $shot->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'usage' => $response->usage->toArray(),
        ]);

        try {
            $attachments = array_map(fn(Media $media) => $painter->referenceFor($media), array_values($images));
            $response = $ask($prompt, $attachments);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('The plan director is unavailable right now. Please try again.')], 503);
        }

        $log($response, $prompt);

        // An idea or keyframes proposed without checking its story and places is asked once more; when that fails, the first answer stands.
        if (PlanDirector::skippedCheck($response->toArray())) {
            try {
                $response = $ask($prompt . PlanDirector::checkReminder(), $attachments);
                $log($response, $prompt . PlanDirector::checkReminder());
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // Shots that break the sequence rules, such as two full shots in a row, are asked for once more the same way.
        if (($problems = PlanDirector::sequenceProblems($response->toArray())) !== []) {
            try {
                $response = $ask($prompt . PlanDirector::sequenceReminder($problems), $attachments);
                $log($response, $prompt . PlanDirector::sequenceReminder($problems));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

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
        // Shown once: the same cast as the last one shown is not shown again under every reply.
        $shownCast = collect($conversation)->reverse()->first(fn(array $turn) => ! empty($turn['cast']))['cast'] ?? [];
        $cast = collect($cast)->sort()->values()->all() === collect($shownCast)->sort()->values()->all() ? [] : $cast;
        $adjusted = $this->adjustElements($elements, $data['adjust_elements'] ?? null);

        // The same plan again for a drawn shot draws nothing new: what is drawn stays.
        $proposal = $keyframes->isNotEmpty() && ShotPlan::isCurrent($shot, $data['proposal'] ?? null) ? null : $data['proposal'] ?? null;

        // A new plan for a drawn shot keeps the keyframes that stay the same; when what they are all drawn from changes, or the director asks, it starts over.
        $planned = $shot->storylineKeyframes();
        $keepsDrawn = $keyframes->isNotEmpty() && DrawnPlan::keeps($shot, $keyframes, $proposal);

        if ($keyframes->isNotEmpty() && ShotPlan::isPlan($proposal) && ! $keepsDrawn) {
            $shot->startPlanOver();
            $keyframes = collect();
        }

        $plan = ShotPlan::apply($shot, $proposal);
        $redrawn = $plan !== null && $keepsDrawn ? DrawnPlan::update($shot, $keyframes, $planned) : [];
        $reply = trim((string) ($data['reply'] ?? ''));
        $chosen = match (true) {
            $plan !== null => ['made' => [], 'waiting' => null],
            $waiting !== null && $waiting['step'] === SequencePlaces::STEP => $this->chooseSequencePlace($shot, $waiting, $sequencePlaces, $data['choice'] ?? null),
            $waiting !== null => $this->choose($shot, $waiting, $choice, $data['choice'] ?? null),
            default => $this->backToPlaces($shot, $keyframes, $data['choice'] ?? null),
        };
        $changes = match (true) {
            $plan !== null || $keyframes->isEmpty() || $chosen['made'] !== [] || ($waiting['step'] ?? null) === SequencePlaces::STEP => ['made' => [], 'waiting' => null],
            $waiting !== null && $waiting['step'] !== FirstKeyframeChoice::CONFIRM => $this->adjustOption($shot, $waiting, $choice, $data['changes'] ?? null),
            default => $this->change($shot, $keyframes, $request, $data['changes'] ?? null, $painter),
        };
        $judged = $plan === null && $findings !== null ? $this->judge($shot, $keyframes, $issues, $findings, $data['verdicts'] ?? null, $conversation) : ['made' => [], 'waiting' => null];
        $spoken = $plan === null ? $this->changeVoiceOver($shot, $data['voice_over'] ?? null) : [];
        $spoken = [...$spoken, ...$this->changeView($shot, $data['view'] ?? null)];
        $changes = ['made' => [...$redrawn, ...$chosen['made'], ...$judged['made'], ...$spoken, ...$changes['made']], 'waiting' => $chosen['waiting'] ?? $judged['waiting'] ?? $changes['waiting']];

        $turns = [
            ...$conversation,
            ...($continuing ? [] : [array_filter(['role' => 'director', 'text' => $message, 'made' => $made, 'about' => $selected])]),
            array_filter(['role' => 'assistant', 'text' => $changes['waiting'] === null ? $reply : "{$reply} {$changes['waiting']}", 'stage' => $stage, 'cast' => $cast, 'adjusted' => $adjusted, 'proposal' => $plan, 'changes' => $changes['made'], 'chosen_place' => $chosen['place'] ?? null, 'rules_check' => self::rulesCheck($data['rules_check'] ?? null)]),
        ];

        $shot->forceFill(['plan_chat' => $turns])->save();

        $sequenceNotes = $this->splitInto($project, $shot, $data['shots'] ?? null);
        $added = count($sequenceNotes);

        // What the agreed sequence means for drawing, shown under the reply, so the director sees which shot uses what and waits for what.
        if ($sequenceNotes !== []) {
            $last = array_key_last($turns);
            $turns[$last]['changes'] = [...($turns[$last]['changes'] ?? []), ...$sequenceNotes];
            $shot->forceFill(['plan_chat' => $turns])->save();
        }
        $removed = $this->removeShots($project, $shot, $data['remove_shots'] ?? null);
        $visit = $this->removeThisShot($project, $shot, $data['remove_shots'] ?? null);

        if ($visit !== null) {
            return response()->json(['messages' => Shot::shownPlanChat($turns, $shot), 'reload' => false, 'visit' => $visit]);
        }

        // Agreed: drawn now, or as soon as the pictures or the setting it needs are there.
        if ($plan !== null && ! $keepsDrawn) {
            $shot->drawKeyframes();
        }

        return response()->json(['messages' => Shot::shownPlanChat($turns, $shot), 'reload' => $plan !== null || $changes['made'] !== [] || $new !== [] || $adjusted !== [] || $added > 0 || $removed !== [] || $offered]);
    }

    /**
     * The plan director's own check of its answer against the director rules,
     * kept on its turn to see later whether it saw a rule; never shown.
     *
     * @return array{places: string, must_read: string, rules: list<int>, story: string, sequence: list<array{framing: string, steps: int, adds: string}>}|null
     */
    private static function rulesCheck(mixed $check): ?array
    {
        if (! is_array($check)) {
            return null;
        }

        return [
            'places' => trim((string) ($check['places'] ?? '')),
            'must_read' => trim((string) ($check['must_read'] ?? '')),
            'rules' => array_values(array_map(intval(...), array_filter((array) ($check['rules'] ?? []), is_numeric(...)))),
            'story' => trim((string) ($check['story'] ?? '')),
            'sequence' => array_values(array_map(fn(array $shot) => ['framing' => (string) ($shot['framing'] ?? ''), 'steps' => (int) ($shot['steps'] ?? 0), 'adds' => trim((string) ($shot['adds'] ?? ''))], array_filter((array) ($check['sequence'] ?? []), is_array(...)))),
        ];
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
     * Gives the shot another view of the place of its sequence, as agreed in
     * the chat: what its camera sees is written anew, and when places or
     * keyframes were drawn from the old view, the shot starts over from new
     * places, since redrawing a keyframe never moves the camera.
     *
     * @return list<string>
     */
    private function changeView(Shot $shot, mixed $view): array
    {
        $view = trim((string) $view);

        if ($view === '' || $shot->sequencePlaceKey() === null || $view === $shot->sequenceView()) {
            return [];
        }

        $drawn = $shot->getMedia(Shot::PLATE_OPTIONS)->isNotEmpty() || $shot->keyframes()->whereNotNull('render_id')->exists();
        $shot->updateStoredJson('storyline', fn(?array $storyline) => [...array_diff_key($storyline ?? [], [SequencePlaces::CAMERA_BRIEF => true]), 'sequence_view' => $view]);

        if ($drawn) {
            $shot->refresh()->startPlanOver();
            $shot->drawKeyframes();
        }

        return [__('New view: :view', ['view' => $view]) . ($drawn ? __('; new places are drawn from it') : '')];
    }

    /**
     * The place of the sequence chosen in the chat, or more options of it drawn.
     *
     * @param  array{step: string, options: Collection<int, Media>, place: array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}}  $waiting
     * @return array{made: list<string>, waiting: string|null, place: array{name: string, media: int}|null}
     */
    private function chooseSequencePlace(Shot $shot, array $waiting, SequencePlaces $places, mixed $said): array
    {
        $action = is_array($said) ? (string) ($said['action'] ?? 'none') : 'none';
        $number = is_array($said) ? max(1, (int) ($said['option'] ?? 1)) : 1;
        $option = $waiting['options']->get($number - 1);

        // What comes next is added to the reply: the chat saves this turn after the choice is made.
        [$act, $made] = match (true) {
            $action === 'choose' && $option !== null => [fn() => $places->choose($shot, $option, say: false), __('Place :n chosen for :place', ['n' => $number, 'place' => $waiting['place']['name']])],
            $action === 'more' => [fn() => $places->more($shot), __('More to choose from for :place', ['place' => $waiting['place']['name']])],
            default => [null, null],
        };

        try {
            $chosen = $act !== null ? $act() : null;
        } catch (ValidationException $exception) {
            return ['made' => [], 'waiting' => '(' . collect($exception->errors())->flatten()->first() . ')', 'place' => null];
        }

        return ['made' => $made !== null ? [$made] : [], 'waiting' => $chosen['text'] ?? null, 'place' => $chosen['place'] ?? null];
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
     * The voice-over as the director asked for it in the chat, with the
     * length it needs: a presenter lasts as long as their text; another shot
     * keeps its length unless a new one was agreed, and the text fits it.
     *
     * @return list<string>
     */
    private function changeVoiceOver(Shot $shot, mixed $said): array
    {
        $text = is_array($said) ? trim((string) ($said['text'] ?? '')) : '';

        if ($text === '' || $text === trim((string) $shot->voice_over)) {
            return [];
        }

        $needed = (int) ceil(GenerateVoiceOver::words($text) / GenerateVoiceOver::WORDS_PER_SECOND);
        $asked = is_array($said) ? (int) ($said['seconds'] ?? 0) : 0;
        $seconds = Shot::clampSeconds(match (true) {
            $shot->isPresenter() => max($needed, $asked),
            $asked > 0 => $asked,
            default => $shot->durationInSeconds(),
        });

        if ($seconds !== $shot->durationInSeconds()) {
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'framing' => [...(array) ($storyline['framing'] ?? []), 'seconds' => $seconds]]);
            $shot->forceFill(['duration' => null]);
        }

        $shot->forceFill(['voice_over' => GenerateVoiceOver::fit($text, max(4, (int) floor($seconds * GenerateVoiceOver::WORDS_PER_SECOND)))])->save();

        return [__('Voice-over, :seconds s: :text', ['seconds' => $seconds, 'text' => $shot->voice_over])];
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
     * shot list and can be merged later. Returns, per shot, what the agreed
     * sequence means for drawing, for the director; empty when nothing is split.
     *
     * @return list<string>
     */
    private function splitInto(Project $project, Shot $shot, mixed $shots): array
    {
        $parts = collect(is_array($shots) ? $shots : [])
            ->filter(fn(mixed $part) => is_array($part) && trim((string) ($part['takeaway'] ?? '')) !== '')
            ->map(fn(array $part) => [
                'takeaway' => trim((string) $part['takeaway']),
                'kind' => ShotKind::tryFrom((string) ($part['kind'] ?? '')) ?? ShotKind::SCENE,
                'idea' => trim((string) ($part['idea'] ?? '')),
                'setting' => (string) ($part['setting'] ?? 'new_place'),
                'same_place_as' => (int) ($part['same_place_as'] ?? 0),
                'cast' => array_values(array_filter(array_map(fn(mixed $name) => trim((string) $name), (array) ($part['cast'] ?? [])), fn(string $name) => $name !== '')),
                'place' => trim((string) ($part['place'] ?? '')),
                'view' => trim((string) ($part['view'] ?? '')),
            ])
            ->values();

        // A montage is one shot with its stills as keyframes, never a sequence of shots.
        if ($parts->count() < 2 || $parts->contains('kind', ShotKind::MONTAGE)) {
            return [];
        }

        $notes = [];

        DB::connection($shot->getConnectionName())->transaction(function () use ($project, $shot, $parts, &$notes) {
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

            // The places the sequence plays in, chosen first in this chat; a shot that continues the one before plays where that one ends.
            $places = self::sequencePlaces($parts->all());
            $placeOf = fn(int $index) => collect($places)->first(fn(array $place) => in_array($index, $place['indexes'], true))['key'] ?? null;
            // A setting from an earlier agreement of the sequence, such as the place of a later shot, makes way for the new one.
            $earlier = ($shot->storyline['setting_from']['sequence'] ?? false) === true && $shot->keyframes()->doesntExist() ? ['setting_from' => true] : [];
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [
                ...array_diff_key($storyline ?? [], ['sequence_place' => true, 'sequence_view' => true, ...$earlier]),
                'sequence_cast' => $first['cast'],
                ...($placeOf(0) !== null ? ['sequence_place' => $placeOf(0)] : []),
                ...($placeOf(0) !== null && $first['view'] !== '' ? ['sequence_view' => $first['view']] : []),
            ]);
            $sequence = [$shot];

            foreach ($parts->skip(1)->values() as $index => $part) {
                $opening = [[
                    'role' => 'assistant',
                    'stage' => 'idea',
                    'text' => __('This shot is part of the sequence we planned: :idea Shall we go on from there?', ['idea' => $part['idea']]),
                ]];
                $cast = [
                    'sequence_cast' => $part['cast'],
                    ...($placeOf($index + 1) !== null ? ['sequence_place' => $placeOf($index + 1)] : []),
                    ...($placeOf($index + 1) !== null && $part['view'] !== '' ? ['sequence_view' => $part['view']] : []),
                ];
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
                        $existing->updateStoredJson('storyline', fn(?array $storyline) => [...array_diff_key($storyline ?? [], ['setting_from' => true, 'sequence_place' => true, 'sequence_view' => true]), ...$cast]);
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
                    'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => [], ...$cast],
                    'plan_chat' => $opening,
                ]);
            }

            // Where each shot plays, as agreed: from where the shot before ends, or in the place of another shot, also a later one.
            $sources = self::settingSources($parts->all());

            foreach ($sources as $index => $source) {
                $target = $sequence[$index];
                $settable = $index === 0 ? $target->keyframes()->doesntExist() : $target->isUntouchedInSequence();

                if ($settable) {
                    $target->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'setting_from' => [
                        'shot_id' => $sequence[$source['from']]->id,
                        'keyframe' => $source['continues'] ? Shot::LAST_KEYFRAME : 0,
                        // Set when the sequence was agreed, so a plan written later keeps it.
                        'sequence' => true,
                    ]]);
                }
            }

            $notes = self::sequenceNotes($sequence, $parts->all(), $sources, $places);
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'sequence_places' => array_map(fn(array $place) => [
                'key' => $place['key'],
                'name' => $place['name'],
                'shots' => array_map(fn(int $index) => $sequence[$index]->code(), $place['indexes']),
                'ideas' => array_map(fn(int $index) => $parts[$index]['idea'], $place['indexes']),
                'elements' => array_values(array_unique(array_merge(...array_map(fn(int $index) => $parts[$index]['cast'], $place['indexes'])))),
            ], $places)]);

            // Each shot that waits in the shot list says from its first message what it uses and where its place comes from.
            foreach (array_slice($sequence, 1, null, true) as $index => $next) {
                $opening = (array) ($next->plan_chat ?? []);

                if ($next->isUntouchedInSequence() && count($opening) === 1) {
                    $opening[0]['text'] .= ' ' . __('For drawing: :note.', ['note' => $notes[$index]]);
                    $next->forceFill(['plan_chat' => $opening])->save();
                }
            }
        });

        // The places are drawn right away, so they are chosen before anything else of the sequence.
        if ($notes !== []) {
            app(SequencePlaces::class)->draw($shot->refresh());
        }

        return $notes;
    }

    /**
     * The places a sequence plays in, by the name each shot gives its place,
     * in the order they first appear. A shot that continues the one before
     * plays where that one ends and needs no place of its own.
     *
     * @param  list<array{setting: string, place: string, kind: ShotKind}>  $parts
     * @return list<array{key: string, name: string, indexes: list<int>}>
     */
    private static function sequencePlaces(array $parts): array
    {
        $places = [];

        foreach ($parts as $index => $part) {
            // Only a close-up continues the shot before; a wider shot after a close-up plays in that close-up's place.
            if ($part['setting'] === 'continues' && $index > 0 && $part['kind'] !== ShotKind::CLOSE_UP && $parts[$index - 1]['kind'] === ShotKind::CLOSE_UP) {
                $part['place'] = $part['place'] !== '' ? $part['place'] : $parts[$index - 1]['place'];
                $part['setting'] = 'new_place';
            }

            if ($part['place'] === '' || ($part['setting'] === 'continues' && $index > 0)) {
                continue;
            }

            $key = Str::slug($part['place']) ?: 'place-' . ($index + 1);
            $places[$key] ??= ['key' => $key, 'name' => $part['place'], 'indexes' => []];
            $places[$key]['indexes'][] = $index;
        }

        return array_values($places);
    }

    /**
     * Which shot of the sequence each shot takes its setting from, by index:
     * the shot before when it continues, or the shot it shares the place
     * with, earlier or later. A chain that would come back to itself, such
     * as a close-up waiting for the place of the next shot while that shot
     * continues from the close-up, is cut at the later shot, which then
     * plays in a place of its own.
     *
     * @param  list<array{setting: string, same_place_as: int, place: string, kind: ShotKind}>  $parts
     * @return array<int, array{from: int, continues: bool}>
     */
    private static function settingSources(array $parts): array
    {
        $sources = [];

        foreach ($parts as $index => $part) {
            $shared = $part['same_place_as'] - 1;

            if ($part['setting'] === 'continues' && $index > 0 && ($part['kind'] === ShotKind::CLOSE_UP || $parts[$index - 1]['kind'] !== ShotKind::CLOSE_UP)) {
                $sources[$index] = ['from' => $index - 1, 'continues' => true];
            } elseif ($part['setting'] === 'same_place' && $shared >= 0 && $shared < $index && $part['place'] === '') {
                $sources[$index] = ['from' => $shared, 'continues' => false];
            }
        }

        foreach (array_keys($sources) as $start) {
            $seen = [$start];
            $at = $start;

            while (isset($sources[$at])) {
                $at = $sources[$at]['from'];

                if (in_array($at, $seen, true)) {
                    unset($sources[max($seen)]);

                    break;
                }

                $seen[] = $at;
            }
        }

        ksort($sources);

        return $sources;
    }

    /**
     * What the agreed sequence means for drawing, in plain lines for the
     * director: who and what each shot uses, and which shot waits for the
     * place or the end of another.
     *
     * @param  list<Shot>  $sequence
     * @param  list<array{cast: list<string>, view: string}>  $parts
     * @param  array<int, array{from: int, continues: bool}>  $sources
     * @param  list<array{key: string, name: string, indexes: list<int>}>  $places
     * @return list<string>
     */
    private static function sequenceNotes(array $sequence, array $parts, array $sources, array $places): array
    {
        return array_map(function (Shot $shot, int $index) use ($sequence, $parts, $sources, $places): string {
            $source = $sources[$index] ?? null;
            $place = collect($places)->first(fn(array $place) => in_array($index, $place['indexes'], true));
            $uses = $parts[$index]['cast'] === [] ? '' : __(' uses :cast', ['cast' => implode(', ', $parts[$index]['cast'])]);
            $where = match (true) {
                $source === null && $place !== null => __('; plays in :place, chosen first', ['place' => $place['name']]) . ($parts[$index]['view'] !== '' ? " ({$parts[$index]['view']})" : ''),
                $source === null => __('; its own place'),
                $source['continues'] => __('; continues from the end of :shot, so it is drawn once the keyframes of :shot are ready', ['shot' => $sequence[$source['from']]->code()]),
                default => __('; plays in the place of :shot', ['shot' => $sequence[$source['from']]->code()]),
            };

            return "{$shot->code()} ({$shot->kindOrScene()->label()}){$uses}{$where}";
        }, $sequence, array_keys($sequence));
    }

    /**
     * Removes this shot itself when the director agreed to drop it and none
     * of its keyframes are drawn yet. Returns where the editor goes next: the
     * shot that took its place, the one before it when it was the last, or a
     * new shot when none are left.
     */
    private function removeThisShot(Project $project, Shot $shot, mixed $codes): ?string
    {
        $wanted = array_map(fn(mixed $code) => mb_strtoupper(str_replace(' ', '', (string) $code)), is_array($codes) ? $codes : []);

        if (! in_array($shot->code(), $wanted, true) || $shot->keyframes()->exists()) {
            return null;
        }

        $position = $shot->position;
        $shot->delete();

        $remaining = $project->shots()->orderBy('position')->get();
        $remaining->each(fn(Shot $sibling, int $index) => $sibling->update(['position' => $index + 1]));

        $next = $remaining->get(min($position, $remaining->count()) - 1);

        return $next === null ? route('public.shots.create', $project) : route('public.shots.view', [$project, $next]);
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
