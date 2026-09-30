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
