<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ElementRoundStatus: string
{
    use EnumHelpers;

    /**
     * Prepared in the background, waiting for the categories before it to
     * queue their renders first, so the grids fill in the order the chat
     * shows them: people, places, objects.
     */
    case WAITING = 'waiting';

    /** The suggestions are being written. */
    case SUGGESTING = 'suggesting';

    /** The suggestions exist; their images may still be rendering. */
    case READY = 'ready';

    /** The director picked from this round. */
    case PICKED = 'picked';

    /** The director skipped this category. */
    case SKIPPED = 'skipped';

    case FAILED = 'failed';

    /**
     * Whether this round settles its category for the intake chat.
     */
    public function settlesCategory(): bool
    {
        return $this === self::PICKED || $this === self::SKIPPED;
    }
}
