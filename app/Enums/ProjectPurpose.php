<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

enum ProjectPurpose: string
{
    use EnumHelpers;

    case E_LEARNING = 'e-learning';

    case EXPLAINER = 'explainer';

    case COMMERCIAL = 'commercial';

    case DOCUMENTARY = 'documentary';

    case SOCIAL_SHORT = 'social-short';

    case NARRATIVE = 'narrative';

    public function description(): string
    {
        return match ($this) {
            self::E_LEARNING => 'E-learning',
            self::EXPLAINER => 'Explainer',
            self::COMMERCIAL => 'Commercial',
            self::DOCUMENTARY => 'Documentary',
            self::SOCIAL_SHORT => 'Social short',
            self::NARRATIVE => 'Narrative',
        };
    }

    /**
     * A one-line summary of what the director optimises for.
     */
    public function summary(): string
    {
        return match ($this) {
            self::E_LEARNING => 'Clarity first. One idea per shot, stable camera, readable keyframes.',
            self::EXPLAINER => 'Understanding first. Clear framing with a little more energy than e-learning.',
            self::COMMERCIAL => 'Emotion and attention. Dynamic angles, movement, tight framing.',
            self::DOCUMENTARY => 'Authenticity. Observational camera, natural staging, honest light.',
            self::SOCIAL_SHORT => 'Hook fast. Vertical friendly, bold framing, quick keyframes.',
            self::NARRATIVE => 'Story. Camera serves character and tension, cuts on motivation.',
        };
    }
}
