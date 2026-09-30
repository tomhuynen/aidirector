<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum StyleOptionStatus: string
{
    use EnumHelpers;

    case PENDING = 'pending';

    case READY = 'ready';

    case FAILED = 'failed';
}
