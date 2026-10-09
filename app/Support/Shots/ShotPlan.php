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
            'description' => trim((string) ($keyframe['description'] ?? '')),
            'spatial' => trim((string) ($keyframe['spatial'] ?? '')),
            'place_change' => trim((string) ($keyframe['place_change'] ?? '')),
            'place_part' => trim((string) ($keyframe['place_part'] ?? '')),
        ]) + [
            'elements' => collect((array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
        ])->all();
        $keyframes = self::withNamedPeople($shot, $keyframes);

        $storyline = trim((string) ($proposal['storyline'] ?? ''));
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
                ...array_diff_key($shot->storyline ?? [], ['setting_from' => true, 'keyframes' => true, 'framing' => true, 'place_edits' => true]),
                'framing' => [
                    // The spot agreed in the chat; a proposal without one keeps the plan's.
                    'spot' => trim((string) ($proposal['spot'] ?? '')) !== '' ? trim((string) $proposal['spot']) : (string) ($shot->storyline['framing']['spot'] ?? ''),
                    'size' => in_array($proposal['framing'] ?? null, ShotSize::sceneValues(), true) ? $proposal['framing'] : ($shot->storylineFraming()['size'] ?? null),
                    'seconds' => $seconds,
                ],
                ...($source !== null ? ['setting_from' => ['shot_id' => $source->id, 'keyframe' => $keyframe]] : []),
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

        return trim((string) ($proposal['storyline'] ?? '')) === trim((string) ($shot->chosen_storyline['storyline'] ?? ''))
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
    private static function settingSource(Shot $shot, mixed $from): ?Shot
    {
        $code = is_array($from) ? str_replace(' ', '', (string) ($from['shot'] ?? '')) : '';

        return $code === '' ? null : $shot->project->shots()->get()->first(fn(Shot $other) => strcasecmp($other->code(), $code) === 0 && ! $other->is($shot));
    }

    /**
     * Adds to each keyframe the people of the cast its description names but
     * its cast list leaves out: without their picture the image model makes
     * them up, often as a copy of someone else in the keyframe.
     *
     * @param  list<array<string, mixed>>  $keyframes
     * @return list<array<string, mixed>>
     */
    public static function withNamedPeople(Shot $shot, array $keyframes): array
    {
        $people = $shot->project->elements()->where('type', ElementType::PERSON)->pluck('name');

        return array_map(function (array $keyframe) use ($people): array {
            $named = $people->filter(fn(string $name) => preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/iu', (string) ($keyframe['description'] ?? '')) === 1);
            $listed = collect((array) ($keyframe['elements'] ?? []));
            $missing = $named->reject(fn(string $name) => $listed->contains(fn(mixed $listedName) => mb_strtolower(trim((string) $listedName)) === mb_strtolower($name)));

            return $missing->isEmpty() ? $keyframe : [...$keyframe, 'elements' => $listed->concat($missing)->values()->all()];
        }, $keyframes);
    }
}
