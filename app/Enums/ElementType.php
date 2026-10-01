<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * The kinds of recurring things a project keeps in its cast and sets.
 */
enum ElementType: string
{
    use EnumHelpers;

    case PERSON = 'person';

    case PLACE = 'place';

    case OBJECT = 'object';

    public function description(): string
    {
        return match ($this) {
            self::PERSON => 'Person',
            self::PLACE => 'Place',
            self::OBJECT => 'Object',
        };
    }

    /**
     * The category name in the intake chat, plural.
     */
    public function plural(): string
    {
        return match ($this) {
            self::PERSON => 'People',
            self::PLACE => 'Places',
            self::OBJECT => 'Objects',
        };
    }

    /**
     * How the element's reference image is staged, so it can be reused on its own.
     */
    public function referenceStaging(): string
    {
        return match ($this) {
            self::PERSON => 'Show only this person, full body, standing upright in a neutral pose, facing the viewer, on a plain light grey background. No other people or objects.',
            self::PLACE => 'Show only this place as a clean, wide establishing view without any people.',
            self::OBJECT => 'Show only this object, whole and centred, on a plain light grey background. No people.',
        };
    }
}
