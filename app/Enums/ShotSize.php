<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How close the camera is. Scenes and montage stills are framed as a full
 * shot; a presenter has its own framing.
 */
enum ShotSize: string
{
    use EnumHelpers;

    case CLOSE_UP = 'close-up';

    case MEDIUM = 'medium';

    case FULL = 'full';

    case WIDE = 'wide';

    /**
     * How the image model frames the keyframe.
     */
    public function framing(): string
    {
        return match ($this) {
            self::CLOSE_UP => 'Close-up at eye level: the hands and the object they handle, or the head and shoulders, fill most of the frame. The spot in the place is only a soft hint behind them.',
            self::MEDIUM => 'Medium shot at eye level, from about the knees or waist up: the people fill most of the height of the frame, the spot in the place fills the background behind them and only a little of the wider place shows at the edges.',
            self::FULL => 'Full shot at eye level: the whole figure from head to feet with some space around it, standing at the spot in the place, with a little of the wider place around that spot.',
            self::WIDE => 'Wide shot: the place is clearly visible around the people, who are small but readable in it. The camera may be raised to show the layout.',
        };
    }
}
