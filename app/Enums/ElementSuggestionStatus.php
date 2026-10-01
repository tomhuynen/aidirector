<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ElementSuggestionStatus: string
{
    use EnumHelpers;

    case PENDING = 'pending';

    case READY = 'ready';

    case FAILED = 'failed';
}
