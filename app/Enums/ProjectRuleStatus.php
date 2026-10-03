<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ProjectRuleStatus: string
{
    use EnumHelpers;

    /** Learned from recurring corrections, waiting for the director. */
    case SUGGESTED = 'suggested';

    /** Confirmed: added to the planner, the image prompts and the check. */
    case ACTIVE = 'active';

    /** Turned down or removed; not suggested again for its category. */
    case DISMISSED = 'dismissed';
}
