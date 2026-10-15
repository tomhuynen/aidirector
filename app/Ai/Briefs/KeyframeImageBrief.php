<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Ai\KeyframeReferences;
use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Models\Keyframe;
use App\Models\Project;
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
     * @param  array{title: string, description: string, spatial?: string|null}  $keyframe
     */
    public static function for(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        if ($shot->isPresenter()) {
            return self::presenter($shot, $keyframe, $references);
        }

        if ($references->first !== null) {
            return self::onFirstKeyframe($shot, $keyframe, $references);
        }

        $style = $shot->project->style;

        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            '',
        ];

        array_push($lines, Keyframe::joined($keyframe['description'], $keyframe['spatial'] ?? null), '');

        if ($references->elements !== []) {
            $pictured = collect($references->elementImages)->map(fn(array $entry) => $entry['element']->getKey())->all();

            $lines[] = 'Cast and sets in this keyframe:';

            foreach ($references->elements as $element) {
                $lines[] = in_array($element->getKey(), $pictured, true)
                    ? "- {$element->name} ({$element->type->value}): looks exactly like its attached picture; its state, such as open, held or lifted off its hook, follows the description."
                    : '- ' . $element->promptLine();
            }

            $lines[] = '';
        }

        $framing = $shot->storylineFraming();
        $lines[] = 'Framing: ' . $shot->kindOrScene()->size()->framing();

        if (filled($framing['spot'] ?? null)) {
            $lines[] = "Spot: {$framing['spot']}";
        }

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        $closeUp = $shot->kindOrScene() === ShotKind::CLOSE_UP;

        // A close-up is about the hands and the object; the scene's composition and staging would pull back to whole figures.
        if ($closeUp) {
            $lines[] = 'Composition: the object and the hands are the subject, large, sharp and in the middle of the frame. Draw only the part of the people the frame shows: their clothing exactly as in their pictures, one layer, never a second jacket or shirt.';
        } else {
            $lines[] = 'Composition: the action is the subject. Put the people and the object they act on, such as a door, a bin or a sign, together in the centre of the frame, large and clear, so they get the most attention. Keep the background simple and subdued: fewer details, softer and lower in contrast than the subject, only enough to show where it is. Everything in the background must make physical sense: vehicles, containers and machines stand on open ground at their real size, never on or against a wall and never overlapping a building; leave them out when there is no room for them.';
            $lines[] = 'Staging: the people are inside the place, in front of a calm part of it that already belongs there; never put a separate wall, panel or backdrop in front of the place. Nothing crosses or touches a figure: no railings, pillars, poles, barriers or machines directly behind or in front of the people. Any sign or context object sits on that surface beside the people, clearly readable, not touching them. The ground near the feet is plain.';
        }
        $lines[] = TextRules::forImages($shot->project);

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh'];
        $attached = 0;

        if ($references->style !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the project\'s style reference sheet. Match its rendering style exactly: the same medium, shading, line work, colours and lighting. Do not copy its subjects, panels or layout.';
        }

        if ($references->place !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the place of this shot. Draw this same place, with its buildings, signs, furniture and objects, colours and light, and put the cast into it as described. Choose the camera and the framing for the action, as the framing says, not the view of the picture.';
        }

        if ($references->setting !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is {$references->settingLabel}. This keyframe plays in exactly that place, at that moment or right after it: keep its furniture, surfaces, objects, colours and light, and the people's clothing. Keep the screen direction: whoever is on the left there is on the left here, and comes in from the same side. Move the camera as the framing says; never copy that image's framing.";
        }

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            if ($element->type === ElementType::PLACE) {
                $lines[] = 'The ' . $ordinals[$attached++] . " attached image shows what {$element->name} looks like: its buildings, shapes, colours and materials. Use it for the look of the place, not for the viewpoint: the camera stands inside the place at the spot described, at eye level, so only part of it shows.";

                continue;
            }

            $lines[] = $closeUp && $element->type === ElementType::PERSON
                ? 'The ' . $ordinals[$attached++] . " attached image is the reference for {$element->name} (person). Use it only for their clothing, skin and hands, exactly as in the picture; draw only the part of {$element->name} this close frame shows. Do not copy its background, pose or framing."
                : 'The ' . $ordinals[$attached++] . " attached image is the reference for {$element->name} ({$element->type->value}). Draw {$element->name} exactly like it: same shape, proportions, colours and details" . ($element->type === ElementType::PERSON ? ', same face, hair, build, clothing and headwear' : '') . ". The picture decides how {$element->name} looks, whatever any other wording says; the description decides its state, such as open, held or lifted off its hook. Do not copy its background or pose.";
        }

        return implode("\n", $lines);
    }

    /**
     * The prompt for the still a presenter speaks from: the person exactly
     * like their picture, frontal from the chest up with a large face, in
     * front of the place softly out of focus, so lip sync can read the face.
     *
     * @param  array{title: string, description: string, spatial?: string|null}  $keyframe
     */
    private static function presenter(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $style = $shot->project->style;
        $lines = [
            "A presenter shot for an e-learning film. Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            Keyframe::joined($keyframe['description'], $keyframe['spatial'] ?? null),
            'Framing: a medium close-up from the chest up. The person is centred, faces the camera straight on and looks into the lens, shoulders square, with a friendly, calm expression and the mouth closed. The head and face fill about a third of the frame height, the eyes in the upper third.',
            'Background: the place, softly out of focus, calm and muted, with no readable details and nothing behind the head that draws attention.',
            'Soft, even light on the face. Only this one person. ' . TextRules::forImages($shot->project),
        ];

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh'];
        $attached = 0;

        if ($references->style !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the project\'s style reference sheet. Match its rendering style exactly. Do not copy its subjects or layout.';
        }

        if ($references->setting !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is {$references->settingLabel}. Draw this same place: the same walls, floor, furniture, objects, colours and light, without its people. The camera for this option decides where it stands.";
        }

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            $lines[] = $element->type === ElementType::PLACE
                ? 'The ' . $ordinals[$attached++] . " attached image shows {$element->name}: use it only for the blurred background."
                : 'The ' . $ordinals[$attached++] . " attached image is the picture of {$element->name}. Draw {$element->name} exactly like it: same face, hair, build, clothing and headwear. Do not copy its background or pose.";
        }

        return implode("\n", $lines);
    }

    /**
     * The prompt for a keyframe after the first: an edit of keyframe 1, which
     * is attached first, so the place, the camera and the style cannot shift.
     * Only the people, their poses and the objects they handle change.
     *
     * @param  array{title: string, description: string, spatial?: string|null}  $keyframe
     */
    private static function onFirstKeyframe(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $castNames = implode(' and ', $references->castNames);

        $lines = [
            'Edit the first attached image. It is keyframe 1 of this shot: the place, seen from a camera that does not move.',
            // The place stays put; what the story changes in it, such as a door that is shut by now, follows the story.
            'Change only what this keyframe needs: the people, their poses and positions, and the objects they handle. The place itself stays exactly as it is in the first image: the walls, the floor and every line on it, signs, windows, fixed machines and the background, at the same place, size and angle, with the same framing, camera, light and style.',
            'The state of things follows the story: open or closed, on or off, full or empty, smoke or none, held or in its place, as this keyframe shows and the keyframe before, even when the first image or a picture shows it otherwise. Whatever is picked up or taken out leaves its spot empty: a handset lifted off its hook leaves the cradle empty, and the object is never drawn twice.',
            match (true) {
                $references->firstShowsCast => 'The people already in the first image move and change pose as described; never add a second copy of anyone.',
                // A close-up shows the hands at work on the surface; the people's sleeves and gloves show who they are.
                $shot->kindOrScene() === ShotKind::CLOSE_UP => "Add the hands and forearms of {$castNames} into it, reaching in from the edge of the frame at the size this close framing gives, with their sleeves, gloves and cuffs exactly as in their pictures; their faces stay out of the frame.",
                default => "{$castNames} " . (count($references->castNames) > 1 ? 'are' : 'is') . ' not in the first image yet: add them into it, standing on the floor of the place at a natural size for where they stand.',
            },
            '',
        ];

        $lines[] = 'This keyframe shows: ' . Keyframe::joined($keyframe['description'], $keyframe['spatial'] ?? null);
        $lines[] = '';

        // The place comes from the first image; the people and objects are named, described in words when they have no picture.
        $pictured = collect($references->elementImages)->map(fn(array $entry) => $entry['element']->getKey())->all();
        $cast = collect($references->elements)->reject(fn($element) => $element->type === ElementType::PLACE);

        if ($cast->isNotEmpty()) {
            $lines[] = 'People and objects in this keyframe:';

            foreach ($cast as $element) {
                $lines[] = in_array($element->getKey(), $pictured, true)
                    ? "- {$element->name} ({$element->type->value}): looks exactly like its attached picture; its state, such as open, held or lifted off its hook, follows the description."
                    : '- ' . $element->promptLine();
            }

            $lines[] = '';
        }

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        $ordinals = ['second', 'third', 'fourth', 'fifth', 'sixth', 'seventh'];
        $attached = 0;

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is the picture of {$element->name} ({$element->type->value}). Draw {$element->name} exactly like it: same shape, proportions, colours and details" . ($element->type === ElementType::PERSON ? ', same face, hair, build, clothing and headwear' : '') . '. It decides how it looks, not its state: open, held or lifted off its hook follows the description. Do not copy its background or pose.';
        }

        if ($references->previous !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . ' attached image is the keyframe directly before this one. Carry over the state and position of every object from it, such as what the people hold, what is in their pockets and what lies in a bin or box, unless this keyframe changes it, and keep the people looking the same as there. Do not copy its pose.';
        }

        $lines[] = TextRules::forImages($shot->project) . ' Signs and markings already in the first image stay where they are.';

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
     * The prompt for correcting the current render of a keyframe with a small change.
     * With `$withPreviousKeyframe` the keyframe before it is attached second, as context;
     * the logos of the branding the change asks for follow, so they are drawn from their picture.
     *
     * @param  list<string>  $logos  the brands of the attached logos, in order
     */
    public static function tweak(Project $project, string $instruction, bool $withPreviousKeyframe = false, array $logos = []): string
    {
        $ordinals = ['second', 'third', 'fourth', 'fifth'];
        $attached = $withPreviousKeyframe ? 1 : 0;

        return implode("\n", array_filter([
            $withPreviousKeyframe || $logos !== [] ? 'Edit the first attached image.' : 'Edit the attached image.',
            "Change only this: {$instruction}",
            'Keep everything else exactly as it is: the character, the environment, the objects, the framing, the colours and the style.',
            $withPreviousKeyframe
                ? 'The second attached image is the keyframe directly before this one in the same shot. Use it to see how the character and the objects look and where they are, and copy them from it when the change asks for something that is missing. Do not copy its pose or framing.'
                : null,
            // At most two logos follow, so the ordinals always reach.
            ...array_map(fn(string $brand) => 'The ' . $ordinals[$attached++] . " attached image is the {$brand} logo.", $logos),
            ($logos === [] ? TextRules::forEdits($project) : TextRules::withLogos($logos)) . ' Signs and markings already in the image stay where they are.',
        ]));
    }
}
