<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * Where a keyframe is in being drawn. A keyframe waits until the keyframe it
 * depends on is ready, and is then drawn and checked.
 */
enum KeyframeStatus: string
{
    use EnumHelpers;

    /** Planned, nothing asked yet. */
    case PLANNED = 'planned';

    /** Asked to be drawn; waits for the keyframe it depends on. */
    case WAITING = 'waiting';

    case DRAWING = 'drawing';

    /** Drawn; the image is being checked. */
    case CHECKING = 'checking';

    case READY = 'ready';

    /** Drawing failed; the error is on the keyframe. */
    case FAILED = 'failed';

    /**
     * Whether work for the keyframe is queued or running.
     */
    public function isBusy(): bool
    {
        return in_array($this, self::busy(), true);
    }

    /**
     * @return list<self>
     */
    public static function busy(): array
    {
        return [self::WAITING, self::DRAWING, self::CHECKING];
    }
}
