<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * What kind of film a shot is. A scene plays at one place with a camera
 * that does not move: every keyframe is drawn on the same place and the
 * video moves through them. A montage is a row of separate stills, each its
 * own place and moment: every still is drawn on its own, animated a little,
 * and the clips are joined with crossfades. A presenter is one person who
 * speaks the voice-over to the camera, lip-synced, once per language. A
 * close-up shows hands and one object large in frame: keyframe 1 is drawn
 * straight away with them, and the other keyframes are drawn on it. An
 * insert shows one thing the viewer must see, such as a sign, alone in one
 * keyframe; its clip is made from that still in code, without the video model.
 */
enum ShotKind: string
{
    use EnumHelpers;

    case SCENE = 'scene';

    case MONTAGE = 'montage';

    case PRESENTER = 'presenter';

    case CLOSE_UP = 'close-up';

    case INSERT = 'insert';

    public function label(): string
    {
        return match ($this) {
            self::SCENE => __('Scene'),
            self::MONTAGE => __('Montage'),
            self::PRESENTER => __('Presenter'),
            self::CLOSE_UP => __('Close-up'),
            self::INSERT => __('Insert'),
        };
    }

    /**
     * How close the camera is for this kind; a presenter has its own framing.
     */
    public function size(): ShotSize
    {
        return match ($this) {
            self::CLOSE_UP => ShotSize::CLOSE_UP,
            self::INSERT => ShotSize::INSERT,
            default => ShotSize::FULL,
        };
    }

    /**
     * A close-up or an insert lasts as long as the director needs to read it; other shots are timed by the planner.
     */
    public function timedByHand(): bool
    {
        return $this === self::CLOSE_UP || $this === self::INSERT;
    }

    /**
     * Whether the keyframes show how long they last: a montage's stills each
     * last their time, a close-up or an insert as the director sets it; a
     * scene is paced by the video model and a presenter by what they say.
     */
    public function showsKeyframeTimes(): bool
    {
        return $this === self::MONTAGE || $this->timedByHand();
    }

    public function keepsOneKeyframe(): bool
    {
        return $this === self::PRESENTER || $this === self::INSERT;
    }

    /**
     * When the planner chooses this kind.
     */
    public function useWhen(): string
    {
        return match ($this) {
            self::SCENE => 'the idea is something people do at one place: an action, a behaviour or a danger, such as putting on gear, stopping behind a line or keeping a door shut',
            self::MONTAGE => 'the idea is an overview, a list or a range that spans places or time, such as "we design, build, repair and service vessels" or the steps of a process at different places',
            self::PRESENTER => 'the idea is said straight to the viewer by a person: a welcome, an introduction, a summary or a call to action',
            self::CLOSE_UP => 'the point is what hands do with one small object, too small to read in a scene: clipping on a badge, closing a padlock or a buckle, reading a gauge, a damaged part handed over',
            self::INSERT => 'the point is one thing the viewer must see on its own for a moment, with no people and no action: a sign, a label, a warning or a gauge, such as "a no-camera sign is visible"',
        };
    }

    /**
     * The kinds for a picker, with a short name and what they are for.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function catalogue(): array
    {
        return [
            ['value' => self::SCENE->value, 'label' => self::SCENE->label(), 'description' => __('One place and one action, with a camera that does not move.')],
            ['value' => self::MONTAGE->value, 'label' => self::MONTAGE->label(), 'description' => __('Separate stills, each its own place, joined with crossfades.')],
            ['value' => self::CLOSE_UP->value, 'label' => self::CLOSE_UP->label(), 'description' => __('Hands and one object, large in frame: a badge, a lock, a buckle, a gauge.')],
            ['value' => self::INSERT->value, 'label' => self::INSERT->label(), 'description' => __('One thing on its own for a moment, such as a sign: one still with a slow push-in.')],
            ['value' => self::PRESENTER->value, 'label' => self::PRESENTER->label(), 'description' => __('One person speaks the voice-over to the camera, lip-synced, in every language of the project.')],
        ];
    }
}
