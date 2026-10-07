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
 * close-up is a scene at one surface where hands and one object fill the
 * frame, drawn on a chosen empty surface like a scene on its place.
 */
enum ShotKind: string
{
    use EnumHelpers;

    case SCENE = 'scene';

    case MONTAGE = 'montage';

    case PRESENTER = 'presenter';

    case CLOSE_UP = 'close-up';

    public function label(): string
    {
        return match ($this) {
            self::SCENE => __('Scene'),
            self::MONTAGE => __('Montage'),
            self::PRESENTER => __('Presenter'),
            self::CLOSE_UP => __('Close-up'),
        };
    }

    /**
     * Whether the keyframes are drawn on one shared empty place, chosen first: a scene's spot, or a close-up's surface.
     */
    public function usesPlace(): bool
    {
        return in_array($this, [self::SCENE, self::CLOSE_UP], true);
    }

    /**
     * How close the camera is for this kind; a presenter has its own framing.
     */
    public function size(): ShotSize
    {
        return $this === self::CLOSE_UP ? ShotSize::CLOSE_UP : ShotSize::FULL;
    }

    /**
     * When the planner chooses this kind.
     */
    public function useWhen(): string
    {
        return match ($this) {
            self::SCENE => 'the takeaway is a behaviour or a danger: something a person does or must not do at one place, such as putting on gear, stopping behind a line or keeping a door shut',
            self::MONTAGE => 'the takeaway is an overview, a list or a range that spans places or time, such as "we design, build, repair and service vessels" or the steps of a process at different places',
            self::PRESENTER => 'the takeaway is said straight to the viewer by a person: a welcome, an introduction, a summary or a call to action',
            self::CLOSE_UP => 'the point is what hands do with one small object, too small to read in a scene: clipping on a badge, closing a padlock or a buckle, reading a gauge, a damaged part handed over',
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
            ['value' => self::PRESENTER->value, 'label' => self::PRESENTER->label(), 'description' => __('One person speaks the voice-over to the camera, lip-synced, in every language of the project.')],
        ];
    }
}
