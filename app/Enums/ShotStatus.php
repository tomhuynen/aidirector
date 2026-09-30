<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ShotStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';

    case OPTIONS_PENDING = 'options-pending';

    case OPTIONS_READY = 'options-ready';

    case STORYLINE_PENDING = 'storyline-pending';

    case STORYLINE_READY = 'storyline-ready';

    case STORYLINE_CHOSEN = 'storyline-chosen';

    case KEYFRAMES_READY = 'keyframes-ready';

    case VIDEO_READY = 'video-ready';

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::OPTIONS_PENDING => 'Suggesting storylines',
            self::OPTIONS_READY => 'Choose a storyline',
            self::STORYLINE_PENDING => 'Writing storyline',
            self::STORYLINE_READY => 'Storyline ready',
            self::STORYLINE_CHOSEN => 'Storyline chosen',
            self::KEYFRAMES_READY => 'Keyframes ready',
            self::VIDEO_READY => 'Video ready',
        };
    }
}
