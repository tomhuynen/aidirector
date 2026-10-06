<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Ai\KeyframeReferences;
use App\Enums\ElementType;
use App\Enums\ShotSize;
use App\Models\Shot;

/**
 * Composes the prompt the image model gets for one keyframe. Keyframe 1 is
 * drawn from the project's visual style, its brief and its cast and sets.
 * Every later keyframe is an edit of keyframe 1, so the place and the camera
 * stay still. Which images are attached, and in which order, is decided by
 * {@see KeyframeReferences}.
 */
class KeyframeImageBrief
{
    /**
     * @param  array{title: string, description: string, prompt?: string, must_show?: string}  $keyframe
     */
    public static function for(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        if ($references->first !== null) {
            return self::onFirstKeyframe($shot, $keyframe, $references);
        }

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

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        if (($shotRules = $shot->rulesBrief()) !== '') {
            $lines[] = "Rules for this shot, never break them:\n{$shotRules}";
        }

        $lines[] = 'Composition: the action is the subject. Put the people and the object they act on, such as a door, a bin or a sign, together in the centre of the frame, large and clear, so they get the most attention. Keep the background simple and subdued: fewer details, softer and lower in contrast than the subject, only enough to show where it is. Everything in the background must make physical sense: vehicles, containers and machines stand on open ground at their real size, never on or against a wall and never overlapping a building; leave them out when there is no room for them.';
        $lines[] = 'Staging: the people are inside the place, in front of a calm part of it that already belongs there; never put a separate wall, panel or backdrop in front of the place. Nothing crosses or touches a figure: no railings, pillars, poles, barriers or machines directly behind or in front of the people. Any sign or context object sits on that surface beside the people, clearly readable, not touching them. The ground near the feet is plain.';
        $lines[] = 'Do not add text, captions or watermarks. Logos, signs and markings that belong to the place stay exactly as they are.';

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh'];
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

        return implode("\n", $lines);
    }

    /**
     * The prompt for a keyframe after the first: an edit of keyframe 1, which
     * is attached first, so the place, the camera and the style cannot shift.
     * Only the people, their poses and the objects they handle change.
     *
     * @param  array{title: string, description: string, prompt?: string, must_show?: string}  $keyframe
     */
    private static function onFirstKeyframe(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $castNames = implode(' and ', $references->castNames);

        $lines = [
            $references->firstIsPlate
                ? 'Edit the first attached image. It is the place of this shot without any people, seen from a camera that does not move.'
                : 'Edit the first attached image. It is keyframe 1 of this shot: the place, seen from a camera that does not move.',
            'Change only what this keyframe needs: the people, their poses and positions, and the objects they handle. Everything else stays exactly as it is in the first image: the walls, doors and door frames, signs, bins, windows, machines, vehicles, the floor and every marking or painted line on it, and the background, all at exactly the same place, size and angle. The framing, the camera, the light and the style stay the same.',
            $references->firstShowsCast
                ? 'The people already in the first image move and change pose as described; never add a second copy of anyone.'
                : "{$castNames} " . (count($references->castNames) > 1 ? 'are' : 'is') . ' not in the first image yet: add them into it, standing on the floor of the place at a natural size for where they stand.'
                    . ($references->firstIsPlate && $references->previous !== null ? ' The keyframe before shows how they look in this shot.' : ''),
            '',
        ];

        if (filled($keyframe['must_show'] ?? null)) {
            $lines[] = "Most important, this must be clearly visible: {$keyframe['must_show']}";
        }

        $lines[] = 'This keyframe shows: ' . ($keyframe['prompt'] ?? $keyframe['description']);
        $lines[] = '';

        // The place comes from the first image; the people and objects are named, described in words when they have no picture.
        $pictured = collect($references->elementImages)->map(fn(array $entry) => $entry['element']->getKey())->all();
        $cast = collect($references->elements)->reject(fn($element) => $element->type === ElementType::PLACE);

        if ($cast->isNotEmpty()) {
            $lines[] = 'People and objects in this keyframe:';

            foreach ($cast as $element) {
                $lines[] = in_array($element->getKey(), $pictured, true)
                    ? "- {$element->name} ({$element->type->value}): looks exactly like its attached picture."
                    : '- ' . $element->promptLine();
            }

            $lines[] = '';
        }

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        if (($shotRules = $shot->rulesBrief()) !== '') {
            $lines[] = "Rules for this shot, never break them:\n{$shotRules}";
        }

        $ordinals = ['second', 'third', 'fourth', 'fifth', 'sixth', 'seventh'];
        $attached = 0;

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is the picture of {$element->name} ({$element->type->value}). Draw {$element->name} exactly like it: same shape, proportions, colours and details" . ($element->type === ElementType::PERSON ? ', same face, hair, build, clothing and headwear' : '') . '. Do not copy its background or pose.';
        }

        if ($references->previous !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the keyframe directly before this one. Carry over the state and position of every object from it, such as what the people hold, what is in their pockets and what lies in a bin or box, unless this keyframe changes it, and keep the people looking the same as there. Do not copy its pose.';
        }

        $lines[] = 'Do not add text, captions or watermarks. Signs and markings already in the first image stay exactly as they are.';

        return implode("\n", $lines);
    }

    /**
     * The prompt for an empty place to start the shot from: the camera, the
     * spot, the light and every object the story needs, written from the
     * whole plan, with room for what the people will do, but without them.
     */
    public static function plate(Shot $shot, KeyframeReferences $references, int $variation): string
    {
        $style = $shot->project->style;
        $framing = $shot->storylineFraming();
        $size = $framing['size'] ?? ShotSize::FULL;
        $steps = collect($shot->storylineKeyframes())->map(fn(array $keyframe, int $index) => ($index + 1) . '. ' . $keyframe['description'])->join("\n");
        $mustShow = collect($shot->storylineKeyframes())->pluck('must_show')->filter(fn($line) => is_string($line) && filled($line))->unique()->map(fn(string $line) => "- {$line}")->join("\n");

        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            '',
            'Draw the place where this shot plays, empty: no people at all. Every keyframe of the shot is drawn on top of this picture later, so the camera, the framing and the place must suit all of them.',
            'Framing: ' . $size->framing() . ' Frame it for the people who will stand at the spot: an adult standing there fills about two thirds of the frame height.',
        ];

        if (filled($framing['spot'] ?? null)) {
            $lines[] = "Spot: {$framing['spot']}";
        }

        $light = $framing['light'] ?? null;

        if (filled($light) && $light !== 'as the visual style') {
            $lines[] = "Light: {$light}. This overrides the lighting in the visual style.";
        }

        array_push($lines, '', "What happens at this spot during the shot, for where things must be; do not draw the people or what they hold:\n{$steps}", '');

        if ($mustShow !== '') {
            array_push($lines, "What the keyframes must show clearly; place the fixed objects and zones so this can happen in this picture, for example a hanging load right above the path someone must not walk:\n{$mustShow}", '');
        }

        array_push(
            $lines,
            'Put every fixed object the story uses where it is needed, such as a sign, a bin, a door, a crane or a marked zone, large enough to read. Leave free floor where the people will stand and walk, and keep a door they go through visible. Leave out anything that only appears during the story, such as an object in someone\'s hand or something put in a bin later.',
            'Keep the background simple and subdued, and everything in it physically sensible. Do not add text, captions or watermarks.',
            self::plateVariation($variation),
        );

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth'];
        $attached = 0;

        if ($references->style !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the project\'s style reference sheet. Match its rendering style exactly. Do not copy its subjects or layout.';
        }

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image shows {$element->name} ({$element->type->value})" . ($element->type === ElementType::PLACE ? ': use it for the look of the place, not for the viewpoint.' : ': draw it exactly like it, at its place in the scene.');
        }

        return implode("\n", $lines);
    }

    /**
     * Keyframes after the first are drawn on top of keyframe 1.
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
     * A camera direction per place option, so the places offer a real choice.
     * The directions repeat for further rounds.
     */
    public static function plateVariation(int $index): string
    {
        $directions = [
            'Stage it as described, with the camera straight on.',
            'Look at the spot from the opposite side of the place: the background behind the spot is different, and objects that stood left now stand right.',
            'Put the camera at a clear angle of about 45 degrees to the side and a little lower, with another part of the place in the background.',
        ];

        return 'Variation for this place: ' . $directions[$index % count($directions)] . ' Keep the framing for the people as above.';
    }

    /**
     * The prompt for a change to a keyframe drawn on a place: the place is
     * edited again, with the current version attached for the people and
     * everything that differs from the empty place, so only the asked change
     * is new and the background stays the place's own.
     */
    public static function tweakOnPlate(string $instruction, bool $withPreviousKeyframe = false, string $shotRules = ''): string
    {
        return implode("\n", array_filter([
            'Edit the first attached image. It is the place of this shot without any people, seen from a camera that does not move. Everything in it stays exactly as it is: the walls, doors, machines, the floor and every marking or painted line on it, and the background, all at exactly the same place, size and angle. The framing, the camera, the light and the style stay the same.',
            'The second attached image is the current version of this keyframe. Put into the place exactly what it shows that the empty place does not: the people with their look, pose, size and position, what they hold, and any thing of the place in another state or position, such as a load that is lowered or a door that is open. Copy only those from it, never its framing or background.',
            "Change only this compared with the current version: {$instruction}",
            $withPreviousKeyframe
                ? 'The third attached image is the keyframe directly before this one in the same shot, for how the people and objects look. Do not copy its pose.'
                : null,
            $shotRules !== '' ? "Rules for this shot, the change never breaks them:\n{$shotRules}" : null,
            'Do not add text, captions or watermarks. Signs and markings already in the first image stay exactly as they are.',
        ]));
    }

    /**
     * The prompt for correcting the current render of a keyframe with a small change.
     * With `$withPreviousKeyframe` the keyframe before it is attached second, as context.
     */
    public static function tweak(string $instruction, bool $withPreviousKeyframe = false, string $shotRules = ''): string
    {
        return implode("\n", array_filter([
            $withPreviousKeyframe ? 'Edit the first attached image.' : 'Edit the attached image.',
            "Change only this: {$instruction}",
            'Keep everything else exactly as it is: the character, the environment, the objects, the framing, the colours and the style.',
            $withPreviousKeyframe
                ? 'The second attached image is the keyframe directly before this one in the same shot. Use it to see how the character and the objects look and where they are, and copy them from it when the change asks for something that is missing. Do not copy its pose or framing.'
                : null,
            $shotRules !== '' ? "Rules for this shot, the change never breaks them:\n{$shotRules}" : null,
            'Do not add text, captions or watermarks. Logos, signs and markings that are already in the image stay exactly as they are.',
        ]));
    }
}
