<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How a person's pose may look in a keyframe: any pose a real person can
 * take naturally, how far someone who walks gets between two frames, and
 * that an act is told without its tempo.
 * Staging and checking use the same rules, so a frame that is staged as
 * asked is never reported as wrong.
 */
final class BodyRules
{
    public const string NATURAL_POSE = 'Every pose is one a real person takes naturally: the neck, arms, wrists and hands bend only the way a real body bends, and the weight rests believably on what carries it, also mid-step, kneeling, crouching or sitting. A head may turn far, such as to look over the shoulder, when the upper body twists with it.';

    public const string NO_TEMPO = 'Describe what happens, never how fast or how long: no words for tempo or pauses such as calmly, gently, slowly, slows, carefully, cautiously, hesitantly, unhurried, quickly, for a beat or for a moment. The video model plays the act at the speed people really move.';

    public const string WALK_DISTANCE = 'Someone who walks between the start frame and the end frame stands as far from their end spot in the start frame as that walk covers, about 0.7 metres a step: for a few steps two to three metres, or coming in at the edge of the frame. Frames closer together than the walk make the video play it in slow motion.';
}
