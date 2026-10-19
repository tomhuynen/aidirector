<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How hands are described in a keyframe. The image model draws what the
 * words name in a hand, so a hand that is about to receive something is
 * described as empty, or it is drawn already holding it. A hand that
 * moves is described where it is at that instant, or it is drawn where it
 * started.
 */
final class HandRules
{
    public const string RECEIVING = 'A hand that is about to receive or take something is open and empty, held out toward it, while the thing is still with whoever gives it or where it lies; describe it that way and never as reaching for the thing or receiving it.';

    public const string AT_THE_INSTANT = 'A keyframe is one still instant: say where each hand is at that instant, never only which way it moves. A hand that draws back from something is already clearly away from it, such as back beside the hip or the thigh, with a gap of at least a hand\'s width.';
}
