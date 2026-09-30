<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Shot;

/**
 * Composes the prompt the image model gets for one keyframe: the project's
 * visual style, the keyframe's own brief and, from the second keyframe on,
 * the instruction to stay consistent with the first rendered keyframe.
 */
class KeyframeImageBrief
{
    /**
     * @param  array{title: string, description: string, prompt?: string}  $keyframe
     */
    public static function for(Shot $shot, array $keyframe, bool $withReference): string
    {
        $style = $shot->project->style;

        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            '',
            $keyframe['prompt'] ?? $keyframe['description'],
            '',
            'No text, captions, logos or watermarks in the image.',
        ];

        if ($withReference) {
            $lines[] = 'The attached image is the previous keyframe of the same shot. Keep the character, the environment, the objects and the style exactly the same; only change what this keyframe describes.';
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
