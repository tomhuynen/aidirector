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

    /** Words and phrases for tempo, removed from an act without what happens. */
    public const array TEMPO_WORDS = ['calmly', 'gently', 'slowly', 'carefully', 'cautiously', 'hesitantly', 'unhurriedly', 'unhurried', 'quickly', 'briefly', 'for a beat', 'for a moment', 'for a second', 'after a beat', 'after a moment', 'after a brief pause', 'after a short pause', 'after a pause', 'with a pause'];

    /** Verbs for a pause or a slowing, and the verb that keeps the event without its tempo. */
    public const array TEMPO_VERBS = ['slows down' => 'stops', 'slowing down' => 'stopping', 'slows' => 'stops', 'slowing' => 'stopping', 'pauses' => 'stops', 'paused' => 'stopped', 'pausing' => 'stopping', 'pause' => 'stop'];

    public const string WALK_DISTANCE = 'Someone who walks between the start frame and the end frame stands as far from their end spot in the start frame as that walk covers, about 0.7 metres a step: for a few steps two to three metres, or coming in at the edge of the frame. Frames closer together than the walk make the video play it in slow motion.';

    /**
     * The act without its tempo: what happens stays, how fast or how long goes.
     */
    public static function withoutTempo(string $text): string
    {
        $words = implode('|', array_map(fn(string $word) => str_replace(' ', '\s+', preg_quote($word, '/')), self::TEMPO_WORDS));
        $tempo = "\b(?:(?:more|very|quite|so)\s+)?(?:{$words})\b";
        $run = "{$tempo}(?:(?:\s*,\s*|\s+and\s+|\s+){$tempo})*";

        $text = (string) preg_replace('/\b([Aa])n\s+unhurried\b/', '$1', $text);

        $text = (string) preg_replace_callback("/(^|[.!?]\s+){$run}\s*,?\s*(\p{L})/iu", fn(array $match) => $match[1] . mb_strtoupper($match[2]), $text);
        $text = (string) preg_replace("/\s*,\s*{$run}\s*,\s*(?=(?:and|then|but)\b)/i", ', ', $text);
        $text = (string) preg_replace("/\s*,\s*{$run}\s*,\s*/i", ' ', $text);
        $text = (string) preg_replace("/\s*{$run}/i", '', $text);

        foreach (self::TEMPO_VERBS as $verb => $replacement) {
            $text = (string) preg_replace_callback(
                '/\b' . str_replace(' ', '\s+', $verb) . '\b/i',
                fn(array $match) => ctype_upper($match[0][0]) ? ucfirst($replacement) : $replacement,
                $text,
            );
        }

        $text = (string) preg_replace('/\b(stops|stop|stopped|stopping)(?:\s*,)?\s+(?:and|then)\s+\1\b/i', '$1', $text);
        $text = (string) preg_replace('/\s+([,.;:!?])/', '$1', $text);
        $text = (string) preg_replace('/,(?=\s*(?:[,.;:!?]|$))/', '', $text);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        return trim($text, " \t\n\r\0\x0B,");
    }
}
