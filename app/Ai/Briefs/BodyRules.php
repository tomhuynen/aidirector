<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How a person's pose may look in a keyframe: any pose a real person can
 * take naturally. Staging and checking use the same rule, so a pose that is
 * staged as asked is never reported as wrong.
 */
final class BodyRules
{
    public const string NATURAL_POSE = 'Every pose is one a real person takes naturally: the neck, arms, wrists and hands bend only the way a real body bends, and the weight rests believably on what carries it, also mid-step, kneeling, crouching or sitting. A head may turn far, such as to look over the shoulder, when the upper body twists with it.';
}
