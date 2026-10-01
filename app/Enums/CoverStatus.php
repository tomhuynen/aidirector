<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * Where the project's cast and sets group picture stands. A project
 * without picked elements never gets one and keeps a null status.
 */
enum CoverStatus: string
{
    use EnumHelpers;

    /** The group picture is being drawn; the intake chat waits for it. */
    case PAINTING = 'painting';

    case READY = 'ready';

    /** Drawing failed; the project page falls back to the style sheet. */
    case FAILED = 'failed';
}
