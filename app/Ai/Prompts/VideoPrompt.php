<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

/**
 * The full prompt for the video model: the shot-specific parts written by the
 * {@see \App\Ai\Agents\VideoPromptWriter} wrapped in fixed instructions that
 * explain the start frame and the end frame and forbid anything they do not show.
 */
class VideoPrompt
{
    /**
     * With an end frame, the clip plays the act at the speed people really
     * move and holds the end frame for the rest, so the shot can be cut
     * where the act ends; without one it holds the start frame for the whole clip.
     *
     * @param  array{style: string, action: string, details: string}  $parts
     */
    public static function compose(array $parts, int $duration, bool $ends): string
    {
        $timing = $ends
            ? 'The act plays at the speed real people move at, never slowed down and never stretched to fill the clip: the end frame is reached as soon as the act is done, and from there everything holds still, exactly as in the end frame, until the clip ends.'
            : 'The shot holds the start frame: nothing changes, only the small natural movement of people who stand still, and the very last frame of the video still matches the start frame.';

        return implode("\n\n", [
            "Create one continuous, natural {$duration}-second animation of one shot, from the supplied start frame to the supplied end frame.",

            "Most important requirement: the very first frame of the video must match the start frame exactly, and the very last frame of the video must match the end frame exactly. Same pose, same position, same framing, same objects and object states, same expression and same gaze. The video starts on the start frame as it is shown, not before it and not after it, and it ends on the end frame as it is shown. {$timing}",

            "Visual style: {$parts['style']}",

            "Action: {$parts['action']}",

            'Generate the natural body motion that takes the people from the start frame to the end frame: hand and arm movement, head rotation, eye direction, facial expression, posture changes, balance and weight transfer, leg movement, and appropriate secondary motion. These connecting movements may be inferred where necessary, but they must only serve to get from the start frame to the end frame.',

            'Do not introduce new story events or visual elements. Do not add new objects, characters, signs, props, camera shots or actions that the two frames do not require. Do not embellish the story. At the same time, do not freeze the character or mechanically morph between the frames: create whatever subtle physical movement is necessary for a believable continuous performance.',

            'Let the people act, as animated characters do: facial expressions that fit the moment, such as concentration, surprise, relief or a friendly smile, eye contact between people, a nod, and small natural reactions to what happens. They bring the moment to life without adding a new action, object or story event.',

            $parts['details'],

            'Keep the camera stationary and maintain the same framing. No cuts, camera transitions, scene changes, morphing, duplicated body parts, disappearing objects, unexplained object movement or changes to the character\'s appearance. No text, captions, numbers or logos. No sound.',

            'Primary objective: faithfully animate the act between the two frames rather than reinterpret it. Preserve what the frames show; invent only the motion necessary to connect them naturally. Remember: the first frame is the start frame exactly and the last frame is the end frame exactly.',
        ]);
    }

    /**
     * The prompt for the clip of one still of a montage: the still is the
     * first frame, and only a small, calm movement brings it to life.
     */
    public static function still(string $description, int $duration): string
    {
        return implode("\n\n", [
            "Bring the supplied image to life for {$duration} seconds. The first frame is the image exactly as it is.",
            "What it shows: {$description}",
            'Animate it with one small, calm movement that belongs to the moment, such as a hand that keeps drawing, sparks that fly, a crane hook that turns, water that ripples or a flag that moves in the wind, and gentle breathing and blinking of the people. The camera holds still or pushes in very slowly.',
            'Keep everything exactly as in the image: the people, their look and pose, the objects, the place, the colours and the style. Nothing new appears and nothing disappears. No cuts, no scene changes, no morphing, no text, captions or logos. No sound.',
        ]);
    }
}
