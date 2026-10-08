<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ShotKind;
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
        if (! is_array($proposal) || ! is_array($proposal['keyframes'] ?? null) || $proposal['keyframes'] === []) {
            return null;
        }

        // The cast and sets by their exact names, whatever case the plan director wrote them in.
        $names = $shot->project->elements()->pluck('name')->keyBy(fn(string $name) => mb_strtolower($name));

        $keyframes = collect($proposal['keyframes'])->filter(fn(mixed $keyframe) => is_array($keyframe))->values()->map(fn(array $keyframe) => array_filter([
            'title' => trim((string) ($keyframe['title'] ?? '')),
            'description' => trim((string) ($keyframe['description'] ?? '')),
            'spatial' => trim((string) ($keyframe['spatial'] ?? '')),
        ]) + [
            'elements' => collect((array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
        ])->all();

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
                ...array_diff_key($shot->storyline ?? [], ['setting_from' => true, 'keyframes' => true, 'framing' => true]),
                'framing' => ['spot' => (string) ($shot->storyline['framing']['spot'] ?? ''), 'seconds' => $seconds],
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
     * The shot of the film the plan takes its setting from, by its code; without one, the default applies.
     */
    private static function settingSource(Shot $shot, mixed $from): ?Shot
    {
        $code = is_array($from) ? str_replace(' ', '', (string) ($from['shot'] ?? '')) : '';

        return $code === '' ? null : $shot->project->shots()->get()->first(fn(Shot $other) => strcasecmp($other->code(), $code) === 0 && ! $other->is($shot));
    }
}
