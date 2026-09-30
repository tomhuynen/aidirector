<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

/**
 * The full prompt for the video model: the shot-specific parts written by the
 * {@see \App\Ai\Agents\VideoPromptWriter} wrapped in fixed instructions that
 * explain the numbered collage and forbid anything the keyframes do not show.
 */
class VideoPrompt
{
    /**
     * @param  array{style: string, action: string, details: string}  $parts
     * @param  list<array{position: int, title: string, seconds: float}>  $timeline
     */
    public static function compose(array $parts, array $timeline, int $duration): string
    {
        $count = count($timeline);

        $anchors = collect($timeline)
            ->map(fn(array $frame) => sprintf('Keyframe %d (%s) at about %.1F s.', $frame['position'], $frame['title'], $frame['seconds']))
            ->join(' ');

        return implode("\n\n", [
            "Create one continuous, natural {$duration}-second animation following the supplied keyframes in chronological order.",

            "The reference image is a storyboard collage of {$count} keyframes, numbered 1 to {$count} in the top-left corner of each panel. The numbers, the white gutters and the panel layout are only there to show the order: they must never appear in the video. The video is a single full-frame shot of the scene shown inside the panels. Timing: {$anchors}",

            "Visual style: {$parts['style']}",

            "Action sequence: {$parts['action']}",

            'Follow every supplied keyframe in chronological order and use them as temporal visual anchors. Preserve the important pose, composition, object placement, gaze direction and action represented by each keyframe.',

            'Generate the natural intermediate body motion needed to connect these keyframes smoothly: hand and arm movement, head rotation, eye direction, posture changes, balance and weight transfer, leg movement, and appropriate secondary motion. These connecting movements may be inferred where necessary, but they must only serve to transition naturally from one supplied keyframe to the next.',

            'Do not introduce new story events or visual elements. Do not add new objects, characters, gestures, signs, props, facial reactions, camera shots or actions that are not required by the keyframe sequence. Do not embellish the story. At the same time, do not freeze the character or mechanically morph between poses: create whatever subtle physical movement is necessary for a believable continuous performance.',

            $parts['details'],

            'Keep the camera stationary and maintain the same framing whenever possible. No cuts, camera transitions, scene changes, morphing, duplicated body parts, disappearing objects, unexplained object movement or changes to the character\'s appearance. No text, captions, numbers or logos. No sound.',

            'Primary objective: faithfully animate the supplied storyboard rather than reinterpret it. Preserve what is shown in the keyframes; invent only the intermediate motion necessary to connect them naturally.',
        ]);
    }
}
