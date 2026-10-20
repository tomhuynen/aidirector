<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A story directed into shots, as the scene director answered it and as it
 * is kept on the chat turn until the director accepts it: the decisive
 * change, the facts a viewer must see, the events of the story, the context
 * they need, and per shot its subject, camera, composition, start frame,
 * action and end frame, with the facts it shows and the events it depicts.
 * With start and end frames drawn per shot, every shot after the first
 * starts from the end frame of the last shot before it with people, as a
 * match cut; an insert takes only the place from it. With the sequence
 * rendered in one clip, every shot keeps its own start frame. The issues are
 * what the review still found in it. Accepted, it becomes the shots of the
 * film: the shot of the chat first, the others right after it, each with
 * its plan filled.
 *
 * @phpstan-type Frame array{title: string, staging: array<string, mixed>, description: string, spatial: string, elements: list<string>}
 * @phpstan-type DirectedShot array{title: string, kind: string, subject: string, purpose: string, notice: string, camera: string, composition: string, reason: string, start: Frame, action: string, end: Frame, seconds: int, cut: string, shows: list<int>, depicts: list<int>}
 */
final class SceneDirection
{
    /**
     * @param  list<string>  $facts
     * @param  list<string>  $events
     * @param  list<string>  $context
     * @param  list<string>  $mustNot
     * @param  list<DirectedShot>  $shots
     * @param  list<string>  $issues
     */
    public function __construct(
        public readonly string $title,
        public readonly string $change,
        public readonly array $facts,
        public readonly array $events,
        public readonly array $context,
        public readonly array $mustNot,
        public readonly array $shots,
        public readonly array $issues = [],
    ) {}

    /**
     * The answer of the scene director, or a stored direction, made whole:
     * the kinds the app has, the cast and sets by their exact names, with
     * every place the staging names among them, the frames as staging, the
     * subject each shot is centred on, and, with start and end frames drawn
     * per shot, every shot after the first starting from the end frame of
     * the last shot before it with people.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function fromArray(array $answer, Project $project): self
    {
        $names = $project->elements()->pluck('name')->keyBy(fn(string $name) => mb_strtolower($name));
        $places = $project->elements()->where('type', ElementType::PLACE)->pluck('name');
        $frame = fn(mixed $frame): array => [
            'title' => trim((string) (is_array($frame) ? ($frame['title'] ?? '') : '')),
            ...KeyframeStaging::fromArray(is_array($frame) ? ($frame['staging'] ?? $frame) : null)->fields(),
            'spatial' => trim((string) (is_array($frame) ? ($frame['spatial'] ?? '') : '')),
            'elements' => collect(is_array($frame) ? (array) ($frame['elements'] ?? []) : [])->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
        ];
        // The place is drawn from its picture only when it is among the elements, which the scene director can leave out.
        $placed = fn(array $frame): array => [...$frame, 'elements' => collect($frame['elements'])
            ->merge($places->filter(fn(string $place) => mb_stripos((string) ($frame['staging']['place'] ?? ''), $place) !== false))
            ->unique()->values()->all()];

        $numbers = fn(mixed $list): array => collect(is_array($list) ? $list : [])->map(fn(mixed $number) => (int) $number)->filter(fn(int $number) => $number > 0)->unique()->sort()->values()->all();
        $continues = ! Shot::rendersSequences();
        $shots = [];
        $before = null;

        foreach (collect(is_array($answer['shots'] ?? null) ? $answer['shots'] : [])->filter(fn(mixed $shot) => is_array($shot)) as $shot) {
            $kind = ShotKind::tryFrom((string) ($shot['kind'] ?? '')) ?? ShotKind::SCENE;

            $shots[] = [
                'title' => Str::limit(trim((string) ($shot['title'] ?? '')), 80, ''),
                'kind' => $kind->value,
                'subject' => $kind->subject(is_string($shot['subject'] ?? null) ? trim($shot['subject']) : null),
                'purpose' => trim((string) ($shot['purpose'] ?? '')),
                'notice' => trim((string) ($shot['notice'] ?? '')),
                'camera' => trim((string) ($shot['camera'] ?? '')),
                'composition' => trim((string) ($shot['composition'] ?? '')),
                'reason' => trim((string) ($shot['reason'] ?? '')),
                // A match cut picks up the instant the last shot with people ended on, in the place as this camera sees it; a still of its own, or a shot of a sequence rendered in one clip, starts from its own frame.
                'start' => $placed($continues && $before !== null && ! $kind->drawsStandalone() ? self::continued($before['end'], $frame($shot['start'] ?? null)) : $frame($shot['start'] ?? null)),
                'action' => trim((string) ($shot['action'] ?? '')),
                'end' => $placed($frame($shot['end'] ?? null)),
                'seconds' => Shot::clampSeconds((int) ($shot['seconds'] ?? 0)),
                'cut' => trim((string) ($shot['cut'] ?? '')),
                'shows' => $numbers($shot['shows'] ?? null),
                'depicts' => $numbers($shot['depicts'] ?? null),
            ];

            if (! $kind->drawsStandalone()) {
                $before = $shots[count($shots) - 1];
            }
        }

        $lines = fn(mixed $list) => collect(is_array($list) ? $list : [])->map(fn(mixed $line) => trim((string) $line))->filter()->values()->all();

        return new self(
            Str::limit(trim((string) ($answer['title'] ?? '')), 80, ''),
            trim((string) ($answer['change'] ?? '')),
            $lines($answer['facts'] ?? null),
            $lines($answer['events'] ?? null),
            $lines($answer['context'] ?? null),
            $lines($answer['must_not'] ?? null),
            $shots,
            $lines($answer['issues'] ?? null),
        );
    }

    /**
     * The direction with the issues the review still found in it.
     *
     * @param  list<string>  $issues
     */
    public function withIssues(array $issues): self
    {
        return new self($this->title, $this->change, $this->facts, $this->events, $this->context, $this->mustNot, $this->shots, $issues);
    }

    /**
     * The start frame of a match cut: the people, what they hold and the
     * spatial fact from the end frame it continues, and the place from this
     * shot's own start frame, so no framing of the shot before comes along.
     *
     * @param  Frame  $end
     * @param  Frame  $own
     * @return Frame
     */
    private static function continued(array $end, array $own): array
    {
        $instant = KeyframeStaging::fromArray($end['staging']);
        $place = KeyframeStaging::fromArray($own['staging'])->place;

        return [...$end, ...(new KeyframeStaging($instant->summary, $place, $instant->cast))->fields()];
    }

    /**
     * The frames that name none of the project's places among their
     * elements, so they would be drawn without the place's picture and
     * reviewed without its layout.
     *
     * @param  list<string>  $places
     * @return list<string>
     */
    public function unplaced(array $places): array
    {
        if ($places === []) {
            return [];
        }

        $problems = [];

        foreach ($this->shots as $index => $shot) {
            foreach (['start' => $shot['start'], 'end' => $shot['end']] as $which => $frame) {
                if (array_intersect($frame['elements'], $places) === []) {
                    $problems[] = 'Shot ' . ($index + 1) . " names no place in its {$which} frame: put the place it plays in, by its exact name, among its elements and in its place field.";
                }
            }
        }

        return $problems;
    }

    /**
     * What keeps the direction from being used, in plain sentences: a fact
     * no shot shows, an event no shot depicts, a shot that names a fact or
     * an event the direction does not have, or a frame that shows what must
     * never happen.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->shots === []) {
            $problems[] = 'The direction has no shots.';
        }

        foreach ($this->facts as $index => $fact) {
            if (! collect($this->shots)->contains(fn(array $shot) => in_array($index + 1, $shot['shows'], true))) {
                $problems[] = "No shot shows: {$fact}";
            }
        }

        foreach ($this->events as $index => $event) {
            if (! collect($this->shots)->contains(fn(array $shot) => in_array($index + 1, $shot['depicts'], true))) {
                $problems[] = "No shot depicts: {$event}";
            }
        }

        foreach ($this->shots as $index => $shot) {
            foreach (array_filter($shot['shows'], fn(int $number) => $number > count($this->facts)) as $number) {
                $problems[] = 'Shot ' . ($index + 1) . " shows fact {$number}, which the facts do not have.";
            }

            foreach (array_filter($shot['depicts'], fn(int $number) => $number > count($this->events)) as $number) {
                $problems[] = 'Shot ' . ($index + 1) . " depicts event {$number}, which the events do not have.";
            }

            foreach ($this->mustNot as $phrase) {
                foreach (['start' => $shot['start'], 'end' => $shot['end']] as $which => $frame) {
                    if (mb_stripos($frame['description'], $phrase) !== false) {
                        $problems[] = 'Shot ' . ($index + 1) . " has in its {$which} frame what must not happen: {$phrase}";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * @return array{title: string, change: string, facts: list<string>, events: list<string>, context: list<string>, must_not: list<string>, shots: list<DirectedShot>, issues: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'change' => $this->change,
            'facts' => $this->facts,
            'events' => $this->events,
            'context' => $this->context,
            'must_not' => $this->mustNot,
            'shots' => $this->shots,
            'issues' => $this->issues,
        ];
    }

    /**
     * The names of the cast and sets any frame of the sequence shows, in order.
     *
     * @return list<string>
     */
    public function elementNames(): array
    {
        return collect($this->shots)->flatMap(fn(array $shot) => [...$shot['start']['elements'], ...$shot['end']['elements']])->unique()->values()->all();
    }

    /**
     * The direction with only the cast and sets the director ticked: each
     * frame keeps those it shows, and gets those its staging names, such as
     * one made after the scene director answered.
     *
     * @param  list<string>  $names
     */
    public function castWith(array $names): self
    {
        $frame = fn(array $frame): array => [...$frame, 'elements' => collect($names)
            ->filter(fn(string $name) => in_array($name, $frame['elements'], true) || mb_stripos($frame['description'], $name) !== false)
            ->values()->all()];

        return $this->withFrames($frame);
    }

    /**
     * The direction without the cast and sets the director left out, in
     * case the scene director still named one.
     *
     * @param  list<string>  $names
     */
    public function without(array $names): self
    {
        $left = array_map(fn(string $name) => mb_strtolower($name), $names);
        $frame = fn(array $frame): array => [...$frame, 'elements' => array_values(array_filter($frame['elements'], fn(string $name) => ! in_array(mb_strtolower($name), $left, true)))];

        return $this->withFrames($frame);
    }

    /**
     * The direction with every start frame and end frame changed by the given function.
     *
     * @param  callable(Frame): Frame  $frame
     */
    private function withFrames(callable $frame): self
    {
        return new self($this->title, $this->change, $this->facts, $this->events, $this->context, $this->mustNot, array_map(
            fn(array $shot) => [...$shot, 'start' => $frame($shot['start']), 'end' => $frame($shot['end'])],
            $this->shots,
        ), $this->issues);
    }

    /**
     * Makes the direction the shots of the film: the shot of the chat is the
     * first, the others follow right after it in its group, each with its
     * plan. Agreed again on a sequence that exists, the shots after this one
     * that are still untouched are updated in order and only extra ones are
     * added. Returns the shots of the sequence, in order.
     *
     * @return Collection<int, Shot>
     */
    public function apply(Project $project, Shot $shot): Collection
    {
        return DB::connection($shot->getConnectionName())->transaction(function () use ($project, $shot): Collection {
            $following = $shot->group_key === null
                ? collect()
                : $project->shots()->where('group_key', $shot->group_key)->where('position', '>', $shot->position)->get()->values();
            $group = $shot->group_key ?? (string) Str::ulid();
            $sequence = collect([$shot]);
            // The last shot with people in its frames: the shots after it continue from its end frame.
            $before = null;

            foreach ($this->shots as $index => $directed) {
                if ($index === 0) {
                    $shot->forceFill(['group_key' => $group, 'idea' => $directed['action']])->save();
                    $this->plan($shot, $directed);
                    $before = $shot->drawsStandalone() ? null : $shot;

                    continue;
                }

                $existing = $following->get($index - 1);

                if ($existing !== null) {
                    // A shot that is drawn or talked about already keeps what it is.
                    if ($existing->isUntouchedInSequence()) {
                        $existing->forceFill(['idea' => $directed['action'], 'plan_chat' => $this->opening($directed)])->save();
                        $this->plan($existing, $directed, $before);
                    }

                    $sequence->push($existing);
                    $before = $existing->drawsStandalone() ? $before : $existing;

                    continue;
                }

                $last = $sequence->last();
                $added = Shot::insertInto($project, $last->position + 1, $group, $last, [
                    'title' => $directed['title'],
                    'idea' => $directed['action'],
                    'kind' => ShotKind::from($directed['kind']),
                    'plan_chat' => $this->opening($directed),
                ]);
                $this->plan($added, $directed, $before);
                $sequence->push($added);
                $before = $added->drawsStandalone() ? $before : $added;
            }

            return $sequence->map(fn(Shot $shot) => $shot->refresh());
        });
    }

    /**
     * Fills the plan of one shot from its direction, and has it continue
     * from the end frame of the last shot with people before it. A montage
     * or a presenter starts on its own.
     *
     * @param  DirectedShot  $directed
     */
    private function plan(Shot $shot, array $directed, ?Shot $before = null): void
    {
        $shot->setRelation('project', $shot->project);

        ShotPlan::apply($shot, [
            'title' => $directed['title'],
            'kind' => $directed['kind'],
            'subject' => $directed['subject'],
            'storyline' => $directed['action'],
            'keyframes' => [$this->proposed($directed['start']), $this->proposed($directed['end'])],
            'seconds' => $directed['seconds'],
            'setting_from' => null,
        ]);

        if ($before !== null && ! $shot->isMontage() && ! $shot->isPresenter()) {
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'setting_from' => ['shot_id' => $before->id, 'keyframe' => Shot::LAST_KEYFRAME]]);
        }

        $shot->forceFill(['status' => ShotStatus::STORYLINE_READY, 'storyline_error' => null])->save();
    }

    /**
     * A frame in the shape of a keyframe the plan director proposes: its staging fields at the top.
     *
     * @param  Frame  $frame
     * @return array<string, mixed>
     */
    private function proposed(array $frame): array
    {
        return ['title' => $frame['title'], ...$frame['staging'], 'spatial' => $frame['spatial'], 'elements' => $frame['elements']];
    }

    /**
     * The first message in the chat of a shot that follows the first.
     *
     * @param  DirectedShot  $directed
     * @return list<array{role: string, stage: string, text: string}>
     */
    private function opening(array $directed): array
    {
        return [[
            'role' => 'assistant',
            'stage' => 'plan',
            'text' => __('This shot is part of the sequence we directed: :action Its start and end frame are planned; say what to change, or draw it.', ['action' => $directed['action']]),
        ]];
    }
}
