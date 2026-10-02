<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How close the camera is, chosen per shot by what it has to communicate.
 * The camera does not move, so every keyframe of a shot shares it.
 */
enum ShotSize: string
{
    use EnumHelpers;

    case CLOSE_UP = 'close-up';

    case MEDIUM = 'medium';

    case FULL = 'full';

    case WIDE = 'wide';

    /**
     * When the planner picks this size.
     */
    public function useWhen(): string
    {
        return match ($this) {
            self::CLOSE_UP => 'the point is what the hands do with an object, such as a cigarette going into a bin or a key being handed over',
            self::MEDIUM => 'the point is a gesture, an expression or two people dealing with each other',
            self::FULL => 'the whole body matters, or where in the place the person stands',
            self::WIDE => 'the surroundings are the point, such as a route, a distance or where something is on the site',
        };
    }

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
