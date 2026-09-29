<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ShotStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';

    case OPTIONS_READY = 'options-ready';

    case PLANNED = 'planned';

    case KEYFRAMES_READY = 'keyframes-ready';

    case VIDEO_READY = 'video-ready';

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::OPTIONS_READY => 'Options ready',
            self::PLANNED => 'Planned',
            self::KEYFRAMES_READY => 'Keyframes ready',
            self::VIDEO_READY => 'Video ready',
        };
    }
}
