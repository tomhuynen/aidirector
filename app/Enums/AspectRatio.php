<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum AspectRatio: string
{
    use EnumHelpers;

    case LANDSCAPE = '16:9';

    case PORTRAIT = '9:16';

    case SQUARE = '1:1';

    public function description(): string
    {
        return match ($this) {
            self::LANDSCAPE => 'Landscape (16:9)',
            self::PORTRAIT => 'Portrait (9:16)',
            self::SQUARE => 'Square (1:1)',
        };
    }
}
