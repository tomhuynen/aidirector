<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How close the camera is: a close-up, or a full shot for scenes and montage
 * stills; a presenter has its own framing.
 */
enum ShotSize: string
{
    use EnumHelpers;

    case CLOSE_UP = 'close-up';

    case FULL = 'full';

    /**
     * How the image model frames the keyframe.
     */
    public function framing(): string
    {
        return match ($this) {
            self::CLOSE_UP => 'Close-up: the object fills about half of the frame, held or worn, with the hands that handle it. Crop from just below the chin to the waist, or tighter on the hands and the object; the face is cut off at the top edge or out of the frame. The place is only a soft, blurred hint behind.',
            self::FULL => 'Full shot at eye level: the whole figure from head to feet with some space around it, standing at the spot in the place, with a little of the wider place around that spot.',
        };
    }
}
