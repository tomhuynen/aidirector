<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How close the camera is: a close-up, an insert of one thing, a medium
 * shot from the waist up, or a full shot for scenes and montage stills; a
 * presenter has its own framing.
 */
enum ShotSize: string
{
    use EnumHelpers;

    case CLOSE_UP = 'close-up';

    case FULL = 'full';

    case MEDIUM = 'medium';

    case INSERT = 'insert';

    /**
     * How the image model frames the keyframe.
     */
    public function framing(): string
    {
        return match ($this) {
            self::CLOSE_UP => 'Close-up: the object fills about half of the frame, held or worn, with the hands that handle it. Crop from just below the chin to the waist, or tighter on the hands and the object; the face is cut off at the top edge or out of the frame. The place is only a soft, blurred hint behind.',
            self::FULL => 'Full shot at eye level: the whole figure from head to feet with some space around it, standing at the spot in the place, with a little of the wider place around that spot.',
            self::MEDIUM => 'Medium shot at eye level: the person from the waist up, the face and both hands in frame and readable, the object they act on in the lower half of the frame; around them a little of the place at the spot, calm and in soft focus.',
            self::INSERT => 'Insert: the one thing fills about half of the frame, seen straight on at its own height, sharp and in the middle; around it a little of the surface it is on, and behind that the place, soft and out of focus.',
        };
    }
}
