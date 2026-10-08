<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ShotStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';

    case STORYLINE_PENDING = 'storyline-pending';

    case STORYLINE_READY = 'storyline-ready';

    case FIRST_KEYFRAME_PENDING = 'first-keyframe-pending';

    case FIRST_KEYFRAME_READY = 'first-keyframe-ready';

    case KEYFRAMES_PENDING = 'keyframes-pending';

    case KEYFRAMES_READY = 'keyframes-ready';

    case VIDEO_PENDING = 'video-pending';

    case VIDEO_READY = 'video-ready';

    /**
     * Whether something is being generated for the shot right now.
     */
    public function isWorking(): bool
    {
        return in_array($this, [
            self::STORYLINE_PENDING,
            self::FIRST_KEYFRAME_PENDING,
            self::KEYFRAMES_PENDING,
            self::VIDEO_PENDING,
        ], true);
    }

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::STORYLINE_PENDING => 'Writing storyline',
            self::STORYLINE_READY => 'Storyline ready',
            self::FIRST_KEYFRAME_PENDING => 'Drawing first keyframe',
            self::FIRST_KEYFRAME_READY => 'Choose first keyframe',
            self::KEYFRAMES_PENDING => 'Generating keyframes',
            self::KEYFRAMES_READY => 'Keyframes ready',
            self::VIDEO_PENDING => 'Rendering video',
            self::VIDEO_READY => 'Video ready',
        };
    }
}
