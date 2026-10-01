<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Shot;

/**
 * Composes the prompt the image model gets for one keyframe: the project's
 * visual style, the keyframe's own brief and the role of each attached
 * image. Attachments are sent in this order: the project's style reference
 * sheet, if one is pinned, then the first rendered keyframe of the shot.
 */
class KeyframeImageBrief
{
    /**
     * @param  array{title: string, description: string, prompt?: string}  $keyframe
     */
    public static function for(Shot $shot, array $keyframe, bool $withStyleReference, bool $withFirstKeyframe): string
    {
        $style = $shot->project->style;

        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            '',
            $keyframe['prompt'] ?? $keyframe['description'],
            '',
            'Staging: the character stands in the foreground in front of one calm, even backdrop surface that fills the area directly behind them from head to feet, such as a facade, a container side, a fence panel, a wall, or open sky. Nothing crosses or touches the figure: no railings, pillars, poles, barriers or machines behind or in front of the character. The wider setting may be visible around and above the backdrop and in the distance, simpler than the character. Any sign or context object sits on the backdrop beside the character, clearly readable, not touching them. The ground near the feet is plain. Show the character fully in frame with space around them.',
            'No text, captions, logos or watermarks in the image.',
        ];

        $ordinal = 'first';

        if ($withStyleReference) {
            $lines[] = 'The first attached image is the project\'s style reference sheet. Match its rendering style exactly: the same medium, shading, line work, colours and lighting. Do not copy its subjects, panels or layout.';
            $ordinal = 'second';
        }

        if ($withFirstKeyframe) {
            $lines[] = "The {$ordinal} attached image is an earlier keyframe of the same shot. Keep the character, the environment, the objects and the style exactly the same; only change what this keyframe describes.";
        }

        return implode("\n", $lines);
    }

    /**
     * A staging direction per option of keyframe 1, so the options differ on purpose.
     * The directions repeat for further batches.
     */
    public static function variation(int $index): string
    {
        $directions = [
            'Stage it as described.',
            'Show more of the surroundings: a wider view where the setting is clearly visible around and above the backdrop, with the sky and the site in the distance.',
            'Choose a different backdrop surface and different lighting for the same setting than an obvious first choice, for example another building side or time of day, still calm and even behind the character.',
        ];

        return 'Variation for this option: ' . $directions[$index % count($directions)];
    }

    /**
     * The prompt for correcting the current render of a keyframe with a small change.
     */
    public static function tweak(string $instruction): string
    {
        return implode("\n", [
            'Edit the attached image.',
            "Change only this: {$instruction}",
            'Keep everything else exactly as it is: the character, the environment, the objects, the framing, the colours and the style.',
            'No text, captions, logos or watermarks in the image.',
        ]);
    }
}
