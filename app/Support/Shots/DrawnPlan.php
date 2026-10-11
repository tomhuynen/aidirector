<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframeImage;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

/**
 * A new plan for a shot that is drawn: the keyframes that stay the same keep
 * their drawing, and only new or changed keyframes are drawn, on the same
 * place. When what every keyframe is drawn from changes, such as keyframe 1,
 * the kind, the framing or the setting, or the director asks to start over,
 * the shot starts over instead.
 */
class DrawnPlan
{
    /**
     * Whether the drawn keyframes can stay with this proposal.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     */
    public static function keeps(Shot $shot, Collection $keyframes, mixed $proposal): bool
    {
        if (! ShotPlan::isPlan($proposal) || ($proposal['start_over'] ?? false) === true) {
            return false;
        }

        if (! in_array($shot->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true) || $keyframes->contains('rendering', true)) {
            return false;
        }

        $before = $shot->storylineKeyframes();
        // With the people their descriptions name, as the plan keeps them.
        $after = ShotPlan::withNamedPeople($shot, array_values(array_filter($proposal['keyframes'], 'is_array')));

        if ($before === [] || $after === [] || ! self::same($before[0], $after[0])) {
            return false;
        }

        // A changed place is made from a keyframe on for every keyframe after it, so it is drawn again from the start.
        foreach (array_slice($after, 0, count($before)) as $index => $keyframe) {
            if (self::text($keyframe, 'place_change') !== self::text($before[$index], 'place_change')) {
                return false;
            }
        }

        $size = $proposal['framing'] ?? null;
        $from = $shot->storyline['setting_from'] ?? null;
        $currentSource = is_array($from) && ($from['sequence'] ?? false) !== true ? [(int) ($from['shot_id'] ?? 0), (int) ($from['keyframe'] ?? 0)] : null;
        $source = ShotPlan::settingSource($shot, $proposal['setting_from'] ?? null);
        $proposedSource = $source === null ? null : [$source->id, max(0, (int) ($proposal['setting_from']['keyframe'] ?? 0))];

        return (string) ($proposal['kind'] ?? '') === (string) $shot->kind?->value
            && ($size === null || $size === ($shot->storylineFraming()['size'] ?? null))
            && $proposedSource === $currentSource;
    }

    /**
     * Brings the drawn keyframes in line with the plan just applied: changed
     * ones are drawn again, new ones added and drawn, the ones the plan no
     * longer has removed. Returns what was done, for the chat.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  list<array<string, mixed>>  $before  the planned keyframes before the new plan
     * @return list<string>
     */
    public static function update(Shot $shot, Collection $keyframes, array $before): array
    {
        $after = $shot->storylineKeyframes();
        $elements = $shot->project->elements()->get()->keyBy(fn(Element $element) => mb_strtolower(trim($element->name)));
        $kept = [];
        $drawn = [];
        $added = [];

        foreach ($after as $index => $plan) {
            $position = $index + 1;
            $keyframe = $keyframes->firstWhere('position', $position);

            if ($keyframe !== null && isset($before[$index]) && self::same($before[$index], $plan)) {
                $keyframe->forceFill(['title' => $plan['title']])->save();
                $kept[] = $position;

                continue;
            }

            $values = [
                'title' => $plan['title'],
                'description' => $plan['description'],
                'spatial' => filled($plan['spatial'] ?? null) ? $plan['spatial'] : null,
                'rendering' => true,
                'render_error' => null,
            ];

            if ($keyframe === null) {
                $keyframe = $shot->keyframes()->create(['position' => $position, ...$values]);
                $added[] = $keyframe;
            } else {
                $keyframe->forceFill($values)->save();
                $drawn[] = $keyframe;
            }

            $keyframe->elements()->sync(collect($plan['elements'] ?? [])->map(fn(string $name) => $elements->get(mb_strtolower(trim($name)))?->id)->filter()->unique()->values()->all());
        }

        $removed = $keyframes->where('position', '>', count($after))->sortBy('position')->values();
        $removed->each->delete();
        $shot->unsetRelation('keyframes');

        if ($drawn === [] && $added === [] && $removed->isEmpty()) {
            return [];
        }

        // The video, the review and the clips were made from the old keyframes.
        $shot->clearMediaCollection(Shot::MONTAGE_CLIPS);
        $shot->clearMediaCollection(Shot::PRESENTER_VIDEOS);
        $shot->forceFill(['keyframe_review' => null, 'montage_clips' => null, 'status' => ShotStatus::KEYFRAMES_READY]);
        $shot->forgetVideo();

        // One after the other, in order, so each is drawn with the keyframes before it.
        $jobs = collect([...$drawn, ...$added])->sortBy('position')->map(fn(Keyframe $keyframe) => new GenerateKeyframeImage($keyframe))->values()->all();

        if ($jobs !== []) {
            Bus::chain($jobs)->onQueue(Config::get('pipeline.queue'))->dispatch();
        }

        return array_values(array_filter([
            $kept === [] ? null : trans_choice('Keyframe :list kept as drawn|Keyframes :list kept as drawn', count($kept), ['list' => self::list($kept)]),
            ...array_map(fn(Keyframe $keyframe) => __('Keyframe :n drawn again', ['n' => $keyframe->position]), $drawn),
            ...array_map(fn(Keyframe $keyframe) => __('Keyframe :n added', ['n' => $keyframe->position]), $added),
            ...$removed->map(fn(Keyframe $keyframe) => __('Keyframe :n removed', ['n' => $keyframe->position]))->all(),
        ]));
    }

    /**
     * Whether a planned keyframe would be drawn the same: the same description, spatial facts and cast and sets.
     *
     * @param  array<string, mixed>  $one
     * @param  array<string, mixed>  $other
     */
    private static function same(array $one, array $other): bool
    {
        $names = fn(array $keyframe) => collect((array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => mb_strtolower(trim((string) $name)))->sort()->values()->all();

        return self::text($one, 'description') === self::text($other, 'description')
            && self::text($one, 'spatial') === self::text($other, 'spatial')
            && $names($one) === $names($other);
    }

    /**
     * @param  array<string, mixed>  $keyframe
     */
    private static function text(array $keyframe, string $key): string
    {
        return trim((string) ($keyframe[$key] ?? ''));
    }

    /**
     * Positions as "1 to 3" when they follow on, otherwise "1, 2 and 4".
     *
     * @param  list<int>  $positions
     */
    private static function list(array $positions): string
    {
        if (count($positions) > 2 && end($positions) - $positions[0] === count($positions) - 1) {
            return __(':from to :to', ['from' => $positions[0], 'to' => end($positions)]);
        }

        return (string) collect($positions)->join(', ', ' and ');
    }
}
