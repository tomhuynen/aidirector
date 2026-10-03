<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Ai\KeyframeReferences;
use App\Enums\ElementType;
use App\Enums\ShotSize;
use App\Models\Shot;

/**
 * Composes the prompt the image model gets for one keyframe: the project's
 * visual style, the keyframe's own brief and the role of each attached
 * image. Which images are attached, and in which order, is decided by
 * {@see KeyframeReferences}: the style sheet, the cast and sets in the
 * keyframe, keyframe 1 and the keyframe directly before.
 */
class KeyframeImageBrief
{
    /**
     * @param  array{title: string, description: string, prompt?: string, must_show?: string}  $keyframe
     */
    public static function for(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $style = $shot->project->style;

        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            '',
        ];

        if (filled($keyframe['must_show'] ?? null)) {
            $lines[] = "Most important, this must be clearly visible: {$keyframe['must_show']}";
            $lines[] = '';
        }

        array_push($lines, $keyframe['prompt'] ?? $keyframe['description'], '');

        if ($references->elements !== []) {
            $pictured = collect($references->elementImages)->map(fn(array $entry) => $entry['element']->getKey())->all();

            $lines[] = 'Cast and sets in this keyframe:';

            foreach ($references->elements as $element) {
                $lines[] = in_array($element->getKey(), $pictured, true)
                    ? "- {$element->name} ({$element->type->value}): looks exactly like its attached picture."
                    : '- ' . $element->promptLine();
            }

            $lines[] = '';
        }

        $framing = $shot->storylineFraming();
        $size = $framing['size'] ?? ShotSize::FULL;

        $lines[] = 'Framing: ' . $size->framing();

        if (filled($framing['spot'] ?? null)) {
            $lines[] = "Spot: {$framing['spot']}";
        }

        if (filled($framing['light'] ?? null)) {
            $lines[] = "Light: {$framing['light']}. This overrides the lighting in the visual style.";
        }

        $lines[] = 'Composition: the action is the subject. Put the people and the object they act on, such as a door, a bin or a sign, together in the centre of the frame, large and clear, so they get the most attention. Keep the background simple and subdued: fewer details, softer and lower in contrast than the subject, only enough to show where it is. Everything in the background must make physical sense: vehicles, containers and machines stand on open ground at their real size, never on or against a wall and never overlapping a building; leave them out when there is no room for them.';
        $lines[] = 'Staging: the people are inside the place, in front of a calm part of it that already belongs there; never put a separate wall, panel or backdrop in front of the place. Nothing crosses or touches a figure: no railings, pillars, poles, barriers or machines directly behind or in front of the people. Any sign or context object sits on that surface beside the people, clearly readable, not touching them. The ground near the feet is plain.';
        $lines[] = 'Do not add text, captions or watermarks. Logos, signs and markings that belong to the place stay exactly as they are.';

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
        $attached = 0;

        if ($references->style !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the project\'s style reference sheet. Match its rendering style exactly: the same medium, shading, line work, colours and lighting. Do not copy its subjects, panels or layout.';
        }

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            if ($element->type === ElementType::PLACE) {
                $lines[] = 'The ' . $ordinals[$attached++] . " attached image shows what {$element->name} looks like: its buildings, shapes, colours and materials. Use it for the look of the place, not for the viewpoint" . ($size === ShotSize::WIDE ? '; a similar overview fits this wide shot.' : ': the camera stands inside the place at the spot described, at eye level, so only part of it shows.');

                continue;
            }

            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is the reference for {$element->name} ({$element->type->value}). Draw {$element->name} exactly like it: same shape, proportions, colours and details" . ($element->type === ElementType::PERSON ? ', same face, hair, build, clothing and headwear' : '') . ". The picture decides how {$element->name} looks, whatever any other wording says. Do not copy its background or pose.";
        }

        if ($references->first !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is keyframe 1 of this shot. Keep the character\'s identity and appearance, the spot in the place, the framing and the style exactly the same as in it. Every fixed part of the place, such as logos, signs, doors, windows and parked vehicles, stays in the same position and looks the same; nothing appears or disappears.';
        }

        if ($references->previous !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the keyframe directly before this one. Carry over the state and position of every object from it, such as what the character holds, what is in their pockets and what hangs at the spot, unless this keyframe\'s description changes it. Do not copy its pose.';
        }

        return implode("\n", $lines);
    }

    /**
     * Keyframes after the first are drawn with keyframe 1 as reference.
     */
    public static function usesFirstKeyframe(int $position): bool
    {
        return $position > 1;
    }

    /**
     * From keyframe 3 on, the keyframe directly before is attached too; for keyframe 2 that is keyframe 1 itself.
     */
    public static function usesPreviousKeyframe(int $position): bool
    {
        return $position > 2;
    }

    /**
     * A staging direction per option of keyframe 1, so the options differ on purpose.
     * The directions repeat for further batches.
     */
    public static function variation(int $index): string
    {
        $directions = [
            'Stage it as described.',
            'Choose a different calm part of the same place for the spot than an obvious first choice, for example another side of the building, keeping the same framing.',
            'Choose different lighting or time of day for the same spot and framing.',
        ];

        return 'Variation for this option: ' . $directions[$index % count($directions)];
    }

    /**
     * The prompt for correcting the current render of a keyframe with a small change.
     * With `$withPreviousKeyframe` the keyframe before it is attached second, as context.
     */
    public static function tweak(string $instruction, bool $withPreviousKeyframe = false): string
    {
        return implode("\n", array_filter([
            $withPreviousKeyframe ? 'Edit the first attached image.' : 'Edit the attached image.',
            "Change only this: {$instruction}",
            'Keep everything else exactly as it is: the character, the environment, the objects, the framing, the colours and the style.',
            $withPreviousKeyframe
                ? 'The second attached image is the keyframe directly before this one in the same shot. Use it to see how the character and the objects look and where they are, and copy them from it when the change asks for something that is missing. Do not copy its pose or framing.'
                : null,
            'Do not add text, captions or watermarks. Logos, signs and markings that are already in the image stay exactly as they are.',
        ]));
    }
}
