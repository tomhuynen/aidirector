<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Jobs\GenerateVoiceOver;
use App\Models\Shot;
use Illuminate\Support\Str;

/**
 * Puts the plan agreed in the plan chat into the shot: the takeaway, the kind,
 * the storyline, the keyframes with the cast and sets they show, the length
 * and where the setting comes from. A plan that changes what happens, or how
 * long it takes, has its voice-over written again.
 */
class ShotPlan
{
    /**
     * @return array{kind: string, settingFrom: string|null}|null what the chat shows of it; null when the proposal has no keyframes
     */
    public static function apply(Shot $shot, mixed $proposal): ?array
    {
        if (! self::isPlan($proposal)) {
            return null;
        }

        // The cast and sets by their exact names, whatever case the plan director wrote them in.
        $names = $shot->project->elements()->pluck('name')->keyBy(fn(string $name) => mb_strtolower($name));

        $keyframes = collect($proposal['keyframes'])->filter(fn(mixed $keyframe) => is_array($keyframe))->values()->map(fn(array $keyframe) => array_filter([
            'title' => trim((string) ($keyframe['title'] ?? '')),
            'beat' => trim((string) ($keyframe['beat'] ?? '')),
            'description' => trim((string) ($keyframe['description'] ?? '')),
            'spatial' => trim((string) ($keyframe['spatial'] ?? '')),
            'place_change' => trim((string) ($keyframe['place_change'] ?? '')),
            'place_part' => trim((string) ($keyframe['place_part'] ?? '')),
        ]) + [
            'elements' => collect((array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
            // What someone changes in this keyframe, and what moves by itself in it: only those objects are taken from the drawing when it is put onto the place.
            'handled' => collect((array) ($keyframe['handled'] ?? []))->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
            // Parts of the place that move in this keyframe, such as a barrier arm, in plain words: taken from the drawing where they were and where they are now.
            'moving_parts' => collect((array) ($keyframe['moving_parts'] ?? []))->map(fn(mixed $part) => trim((string) $part))->filter()->unique()->values()->all(),
        ])->all();
        $keyframes = self::withNamedPeople($shot, $keyframes);

        $storyline = self::storylineFrom($keyframes, (string) ($proposal['storyline'] ?? ''));
        $takeaway = trim((string) ($proposal['takeaway'] ?? ''));
        $seconds = Shot::clampSeconds((int) ($proposal['seconds'] ?? $shot->durationInSeconds()));
        $source = self::settingSource($shot, $proposal['setting_from'] ?? null);
        $keyframe = max(0, (int) ($proposal['setting_from']['keyframe'] ?? 0));

        $changed = $storyline !== trim((string) ($shot->chosen_storyline['storyline'] ?? ''))
            || array_column($keyframes, 'description') !== array_column($shot->storylineKeyframes(), 'description')
            || $seconds !== $shot->durationInSeconds();

        $shot->forceFill([
            ...($takeaway !== '' ? ['takeaway' => $takeaway, 'title' => Str::limit($takeaway, 80)] : []),
            'kind' => ShotKind::tryFrom((string) ($proposal['kind'] ?? '')) ?? $shot->kind,
            // A length timed in the chat replaces one set in the brief.
            'duration' => null,
            'chosen_storyline' => ['title' => (string) ($takeaway !== '' ? Str::limit($takeaway, 80) : $shot->title), 'storyline' => $storyline],
            'storyline' => [
                ...array_diff_key($shot->storyline ?? [], ['setting_from' => true, 'keyframes' => true, 'framing' => true, 'place_edits' => true, ...(self::leavesSequencePlace($shot, $keyframes) ? ['sequence_place' => true, 'sequence_view' => true] : [])]),
                'framing' => [
                    // The spot agreed in the chat; a proposal without one keeps the plan's.
                    'spot' => trim((string) ($proposal['spot'] ?? '')) !== '' ? trim((string) $proposal['spot']) : (string) ($shot->storyline['framing']['spot'] ?? ''),
                    'size' => in_array($proposal['framing'] ?? null, ShotSize::sceneValues(), true) ? $proposal['framing'] : ($shot->storylineFraming()['size'] ?? null),
                    'seconds' => $seconds,
                ],
                ...($source !== null ? ['setting_from' => ['shot_id' => $source->id, 'keyframe' => $keyframe]] : self::sequenceSetting($shot)),
                'keyframes' => $keyframes,
            ],
            ...($changed ? ['voice_over' => null] : []),
        ])->save();

        if ($shot->voice_over === null) {
            GenerateVoiceOver::dispatch($shot);
        }

        return [
            'kind' => $shot->kindOrScene()->value,
            'settingFrom' => $source === null ? null : $source->code() . ' · ' . ($keyframe > 0 ? __('keyframe :n', ['n' => $keyframe]) : __('place')),
        ];
    }

    /**
     * Whether a proposal is the plan the shot already has: the same kind,
     * storyline and keyframes. Giving it again draws nothing new.
     */
    public static function isCurrent(Shot $shot, mixed $proposal): bool
    {
        if (! self::isPlan($proposal)) {
            return false;
        }

        $descriptions = collect($proposal['keyframes'])->map(fn(mixed $keyframe) => trim((string) (is_array($keyframe) ? ($keyframe['description'] ?? '') : '')))->all();

        return self::storylineFrom(array_values(array_filter($proposal['keyframes'], is_array(...))), (string) ($proposal['storyline'] ?? '')) === trim((string) ($shot->chosen_storyline['storyline'] ?? ''))
            && $descriptions === array_map(fn(array $keyframe) => trim((string) $keyframe['description']), $shot->storylineKeyframes())
            && (string) ($proposal['kind'] ?? '') === (string) $shot->kind?->value;
    }

    /**
     * Whether the proposal is a plan to apply: it has keyframes.
     *
     * @phpstan-assert-if-true array<string, mixed> $proposal
     */
    public static function isPlan(mixed $proposal): bool
    {
        return is_array($proposal) && is_array($proposal['keyframes'] ?? null) && $proposal['keyframes'] !== [];
    }

    /**
     * The shot of the film the plan takes its setting from, by its code; without one, the default applies.
     */
    public static function settingSource(Shot $shot, mixed $from): ?Shot
    {
        $code = is_array($from) ? str_replace(' ', '', (string) ($from['shot'] ?? '')) : '';

        return $code === '' ? null : $shot->project->shots()->get()->first(fn(Shot $other) => strcasecmp($other->code(), $code) === 0 && ! $other->is($shot));
    }

    /**
     * Whether the plan moves the shot to another place than the one chosen
     * for its sequence: the plan before named a place of the cast and sets,
     * and the new one names only places that neither it nor the shots of that
     * place use, such as a plain hallway made instead of the meeting-room
     * corridor. The chosen place would otherwise still be drawn.
     *
     * @param  list<array<string, mixed>>  $keyframes
     */
    private static function leavesSequencePlace(Shot $shot, array $keyframes): bool
    {
        $place = app(SequencePlaces::class)->of($shot);
        $owner = $place === null ? null : app(SequencePlaces::class)->ownerOf($shot);

        if ($owner === null) {
            return false;
        }

        $places = $shot->project->elements()->where('type', ElementType::PLACE)->pluck('name')->map(fn(string $name) => mb_strtolower($name));
        $placesIn = fn(array $plan) => collect($plan)->flatMap(fn(array $keyframe) => array_map(fn(mixed $name) => mb_strtolower(trim((string) $name)), (array) ($keyframe['elements'] ?? [])))->unique()->filter(fn(string $name) => $places->contains($name))->values();
        $before = $placesIn($shot->storylineKeyframes())->all();
        $used = [...$before, ...array_map(mb_strtolower(...), collect(app(SequencePlaces::class)->places($owner))->firstWhere('key', $shot->sequencePlaceKey())['elements'] ?? [])];
        $now = $placesIn($keyframes);

        // Only a switch: the plan before named a place, and the new one names none of it.
        return $before !== [] && $now->isNotEmpty() && $now->every(fn(string $name) => ! in_array($name, $used, true));
    }

    /**
     * The setting agreed when the shot's sequence was planned, such as the
     * place of a later shot, kept when the plan itself names none.
     *
     * @return array{setting_from?: array<string, mixed>}
     */
    private static function sequenceSetting(Shot $shot): array
    {
        $from = $shot->storyline['setting_from'] ?? null;

        return is_array($from) && ($from['sequence'] ?? false) === true ? ['setting_from' => $from] : [];
    }

    /**
     * The storyline as the beats of the keyframes in order, so each sentence belongs to one keyframe; the written
     * storyline when a keyframe has no beat, as in a plan from before beats.
     *
     * @param  list<array<string, mixed>>  $keyframes
     */
    public static function storylineFrom(array $keyframes, string $written): string
    {
        $beats = array_map(fn(array $keyframe) => trim((string) ($keyframe['beat'] ?? '')), $keyframes);

        return $beats !== [] && ! in_array('', $beats, true) ? implode(' ', $beats) : trim($written);
    }

    /**
     * Makes the people of each keyframe the ones its description names: a
     * person named but left out of its cast list is added, so the image model
     * gets their picture instead of making them up, often as a copy of someone
     * else; a person in the list whom the description never names, while it
     * names someone else, is dropped, because the image model would put them
     * in the picture anyway, standing there with nothing to do. Places and
     * objects stay as listed, and anything handled but left out of the list
     * is added: only what is listed is cut out of the drawing and can be
     * moved, so a helicopter flying in that is only handled would vanish.
     *
     * @param  list<array<string, mixed>>  $keyframes
     * @return list<array<string, mixed>>
     */
    public static function withNamedPeople(Shot $shot, array $keyframes): array
    {
        $elements = $shot->project->elements()->get(['name', 'type']);
        $people = $elements->where('type', ElementType::PERSON)->pluck('name');
        $isPerson = fn(mixed $name) => $people->contains(fn(string $person) => mb_strtolower($person) === mb_strtolower(trim((string) $name)));
        $word = fn(string $name) => '/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/iu';

        return array_map(function (array $keyframe) use ($people, $isPerson, $elements, $word): array {
            // A name inside a longer one, such as "Reception security" in "Reception Security Desk", does not name that person.
            $named = $people->filter(function (string $name) use ($keyframe, $elements, $word): bool {
                $text = (string) ($keyframe['description'] ?? '');

                foreach ($elements->pluck('name')->filter(fn(string $other) => mb_strlen($other) > mb_strlen($name) && preg_match($word($name), $other) === 1) as $longer) {
                    $text = (string) preg_replace($word($longer), '', $text);
                }

                return preg_match($word($name), $text) === 1;
            });
            $isNamed = fn(mixed $name) => $named->contains(fn(string $person) => mb_strtolower($person) === mb_strtolower(trim((string) $name)));
            $listed = collect((array) ($keyframe['elements'] ?? []));
            // Only when the description names someone: "she" alone may well be the person listed.
            $kept = $named->isEmpty() ? $listed : $listed->reject(fn(mixed $name) => $isPerson($name) && ! $isNamed($name));
            $isListed = fn(string $name) => $listed->contains(fn(mixed $listedName) => mb_strtolower(trim((string) $listedName)) === mb_strtolower(trim($name)));
            $handled = collect((array) ($keyframe['handled'] ?? []))->map(fn(mixed $name) => trim((string) $name))->filter()
                ->map(fn(string $name) => $elements->first(fn($element) => mb_strtolower($element->name) === mb_strtolower($name))?->name)->filter();
            $missing = $named->concat($handled)->reject($isListed)->unique(fn(string $name) => mb_strtolower($name));

            return $missing->isEmpty() && $kept->count() === $listed->count() ? $keyframe : [...$keyframe, 'elements' => $kept->concat($missing)->values()->all()];
        }, $keyframes);
    }
}
