<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How hands are described in a keyframe. The image model draws what the
 * words name in a hand, so a hand that is about to receive something is
 * described as empty, or it is drawn already holding it.
 */
final class HandRules
{
    public const string RECEIVING = 'A hand that is about to receive or take something is open and empty, held out toward it, while the thing is still with whoever gives it or where it lies; describe it that way and never as reaching for the thing or receiving it.';
}
