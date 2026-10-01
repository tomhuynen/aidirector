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

    case FIRST_KEYFRAME_PENDING = 'first-keyframe-pending';

    case FIRST_KEYFRAME_READY = 'first-keyframe-ready';

    case ELEMENTS_PENDING = 'elements-pending';

    case ELEMENTS_READY = 'elements-ready';

    case KEYFRAMES_PENDING = 'keyframes-pending';

    case KEYFRAMES_READY = 'keyframes-ready';

    case VIDEO_PENDING = 'video-pending';

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
            self::FIRST_KEYFRAME_PENDING => 'Drawing first keyframe',
            self::FIRST_KEYFRAME_READY => 'Choose first keyframe',
            self::ELEMENTS_PENDING => 'Finding cast & sets',
            self::ELEMENTS_READY => 'Review cast & sets',
            self::KEYFRAMES_PENDING => 'Generating keyframes',
            self::KEYFRAMES_READY => 'Keyframes ready',
            self::VIDEO_PENDING => 'Rendering video',
            self::VIDEO_READY => 'Video ready',
        };
    }
}
