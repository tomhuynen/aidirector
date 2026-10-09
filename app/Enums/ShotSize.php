<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How close the camera is: a close-up, a medium or full shot for scenes, a
 * full shot for montage stills; a presenter has its own framing.
 */
enum ShotSize: string
{
    use EnumHelpers;

    case CLOSE_UP = 'close-up';

    case MEDIUM = 'medium';

    case FULL = 'full';

    /**
     * The sizes the plan chooses from for a scene.
     *
     * @return list<string>
     */
    public static function sceneValues(): array
    {
        return [self::MEDIUM->value, self::FULL->value];
    }

    /**
     * How the image model frames the keyframe.
     */
    public function framing(): string
    {
        return match ($this) {
            self::CLOSE_UP => 'Close-up: the object fills about half of the frame, held or worn, with the hands that handle it. Crop from just below the chin to the waist, or tighter on the hands and the object; the face is cut off at the top edge or out of the frame. The place is only a soft, blurred hint behind.',
            self::MEDIUM => 'Medium shot at eye level: the people from the knees up, their faces, hands and what they hold large and easy to read, with the object they act on beside them in the frame and only a little of the place around them.',
            self::FULL => 'Full shot at eye level: the whole figure from head to feet with some space around it, standing at the spot in the place, with a little of the wider place around that spot.',
        };
    }

    /**
     * How large a person standing at the spot is, for framing the empty place they are drawn on later.
     */
    public function personScale(): string
    {
        return match ($this) {
            self::MEDIUM => 'an adult standing there is seen from the knees up and fills the height of the frame',
            default => 'an adult standing there fills about two thirds of the frame height',
        };
    }
}
