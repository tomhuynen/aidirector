<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Models\Shot;

/**
 * The overview of a sequence for the director to agree on before anything is
 * drawn: every shot with its kind and what happens in each of its keyframes,
 * the beats of its plan once it is planned, otherwise those agreed with the
 * sequence. A shot on its own is a sequence of one.
 */
class SequenceOverview
{
    /** On the storyline of a shot of a sequence: its beats as agreed with the sequence, before it is planned. */
    public const string BEATS = 'sequence_beats';

    /**
     * @return list<array{id: string, code: string, kind: string, current: bool, planned: bool, beats: list<string>}>
     */
    public static function of(Shot $shot): array
    {
        $shots = $shot->group_key === null
            ? collect([$shot])
            : Shot::query()->where('project_id', $shot->project_id)->where('group_key', $shot->group_key)->whereNull('merged_into_id')->orderBy('position')->get();

        return $shots->map(function (Shot $member) use ($shot): array {
            $planned = $member->storylineKeyframes() !== [];
            $agreed = array_values(array_filter(array_map(fn(mixed $beat) => trim((string) $beat), (array) ($member->storyline[self::BEATS] ?? []))));
            $beats = match (true) {
                $planned => array_map(fn(array $keyframe) => trim((string) ($keyframe['beat'] ?? '')) ?: (string) $keyframe['title'], $member->storylineKeyframes()),
                $agreed !== [] => $agreed,
                default => filled($member->notes) ? [(string) $member->notes] : [],
            };

            return [
                'id' => $member->sqid,
                'code' => $member->code(),
                'kind' => $member->kindOrScene()->label(),
                'current' => $member->is($shot),
                'planned' => $planned,
                'beats' => $beats,
            ];
        })->values()->all();
    }
}
