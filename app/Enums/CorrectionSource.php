<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * Where a correction came from: something the director changed by hand, or a
 * mistake the automatic keyframe check found.
 */
enum CorrectionSource: string
{
    use EnumHelpers;

    case ADJUSTMENT = 'adjustment';

    case DESCRIPTION = 'description';

    case DELETE = 'delete';

    case FEEDBACK = 'feedback';

    case CHECK = 'check';
}
