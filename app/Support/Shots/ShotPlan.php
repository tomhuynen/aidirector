<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ShotKind;
use App\Jobs\GenerateVoiceOver;
use App\Models\Shot;
use Illuminate\Support\Str;

/**
 * Puts the plan agreed in the plan chat into the shot: the title, the kind,
 * the storyline, the keyframes with the cast and sets they show, the length,
 * what the frame is centred on and where the setting comes from. A plan
 * without a subject keeps the one the shot has while its kind stays. A scene or close-up keeps its start frame
 * and its end frame, whatever lies between them in the proposal. A plan that
 * changes what happens, or how long it takes, has its voice-over written again.
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
            ...KeyframeStaging::fromArray($keyframe)->fields(),
            'spatial' => trim((string) ($keyframe['spatial'] ?? '')),
        ]) + [
            'elements' => collect((array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => $names[mb_strtolower(trim((string) $name))] ?? null)->filter()->unique()->values()->all(),
            ...(is_numeric($keyframe['seconds'] ?? null) ? ['seconds' => max(0.0, (float) $keyframe['seconds'])] : []),
        ])->all();

        $kind = ShotKind::tryFrom((string) ($proposal['kind'] ?? '')) ?? $shot->kindOrScene();

        // A scene or close-up is its start frame and its end frame; a step proposed in between is not drawn.
        if (count($keyframes) > 2 && ! (new Shot(['kind' => $kind]))->drawsStandalone()) {
            $keyframes = [$keyframes[0], $keyframes[count($keyframes) - 1]];
        }

        // The start frame of a shot that opens with a copy is that image: its plan stays as it was copied.
        if ($shot->opensWithCopy() && isset($shot->storylineKeyframes()[0])) {
            $keyframes[0] = $shot->storylineKeyframes()[0];
        }

        $subject = match (true) {
            in_array($proposal['subject'] ?? null, ShotKind::SUBJECTS, true) => $kind->subject($proposal['subject']),
            $kind === $shot->kindOrScene() => $shot->storylineFraming()['subject'] ?? null,
            default => null,
        };
        $storyline = trim((string) ($proposal['storyline'] ?? ''));
        $title = trim((string) ($proposal['title'] ?? ''));
        $seconds = Shot::clampSeconds((int) ($proposal['seconds'] ?? $shot->durationInSeconds()));
        $source = self::settingSource($shot, $proposal['setting_from'] ?? null);
        $keyframe = max(0, (int) ($proposal['setting_from']['keyframe'] ?? 0));

        $changed = $storyline !== trim((string) ($shot->chosen_storyline['storyline'] ?? ''))
            || array_column($keyframes, 'description') !== array_column($shot->storylineKeyframes(), 'description')
            || $seconds !== $shot->durationInSeconds();

        $shot->forceFill([
            ...($title !== '' ? ['title' => Str::limit($title, 80)] : []),
            'kind' => $kind,
            // A length timed in the chat replaces one set in the brief.
            'duration' => null,
            'chosen_storyline' => ['storyline' => $storyline],
            'storyline' => [
                ...array_diff_key($shot->storyline ?? [], ['setting_from' => true, 'keyframes' => true, 'framing' => true]),
                'framing' => ['spot' => (string) ($shot->storyline['framing']['spot'] ?? ''), 'seconds' => $seconds, 'subject' => $subject],
                ...($source !== null ? ['setting_from' => ['shot_id' => $source->id, 'keyframe' => $keyframe]] : []),
                'keyframes' => $keyframes,
            ],
            ...($changed ? ['voice_over' => null] : []),
        ])->save();

        if ($shot->voice_over === null) {
            GenerateVoiceOver::automatically($shot);
        }

        return [
            'kind' => $shot->kindOrScene()->value,
            'settingFrom' => $source === null ? null : $source->code() . ' · ' . ($keyframe > 0 ? __('keyframe :n', ['n' => $keyframe]) : __('place')),
        ];
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
}
