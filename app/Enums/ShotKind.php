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
 * speaks the voice-over to the camera, lip-synced, once per language.
 */
enum ShotKind: string
{
    use EnumHelpers;

    case SCENE = 'scene';

    case MONTAGE = 'montage';

    case PRESENTER = 'presenter';

    public function label(): string
    {
        return match ($this) {
            self::SCENE => __('Scene'),
            self::MONTAGE => __('Montage'),
            self::PRESENTER => __('Presenter'),
        };
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
            ['value' => self::PRESENTER->value, 'label' => self::PRESENTER->label(), 'description' => __('One person speaks the voice-over to the camera, lip-synced, in every language of the project.')],
        ];
    }
}
