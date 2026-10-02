<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * How the clips of a merged shot follow each other.
 */
enum ShotTransition: string
{
    use EnumHelpers;

    case CUT = 'cut';

    /** The next clip fades in over the last moment of the one before. */
    case CROSSFADE = 'crossfade';

    /** The clip fades out to black and the next one fades in from black. */
    case FADE_BLACK = 'fade-black';

    public function description(): string
    {
        return match ($this) {
            self::CUT => __('Cut'),
            self::CROSSFADE => __('Crossfade'),
            self::FADE_BLACK => __('Fade through black'),
        };
    }

    /**
     * The ffmpeg xfade transition, or null for a hard cut.
     */
    public function xfade(): ?string
    {
        return match ($this) {
            self::CUT => null,
            self::CROSSFADE => 'fade',
            self::FADE_BLACK => 'fadeblack',
        };
    }
}
