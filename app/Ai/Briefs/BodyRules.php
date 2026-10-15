<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How a person's body turns in a keyframe. An image model that is told to
 * turn only the head or an arm twists it against a body that still faces
 * the other way, which looks unnatural.
 */
final class BodyRules
{
    public const string TURNING = 'A person turns as a whole: when they look at or aim at something that is not in front of them, their shoulders and hips turn toward it with the head and their feet turn on the spot, so the body faces what they look at; only a small glance turns the head alone. Never twist a head or an arm against a body that faces elsewhere.';
}
