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
 * Composes the prompt the image model gets for one keyframe. The start frame
 * is drawn from the project's visual style, its brief and its cast and sets.
 * The end frame is an edit of the start frame, so the place and the camera
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

        if ($references->view !== null) {
            return self::backToView($shot, $keyframe, $references);
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
        $lines[] = 'Framing: ' . $shot->kindOrScene()->size()->framingFor($framing['subject'] ?? null);

        if (filled($framing['spot'] ?? null)) {
            $lines[] = "Spot: {$framing['spot']}";
        }

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        $closeUp = $shot->kindOrScene() === ShotKind::CLOSE_UP;
        $face = $shot->showsFace();

        // A close-up is about the hands and the object, or a face; the scene's composition and staging would pull back to whole figures.
        if ($shot->isInsert()) {
            $lines[] = 'Composition: the one thing is the only subject, large, sharp and readable in the middle of the frame. No people, no hands and no other objects in front of it.';
        } elseif ($face) {
            $lines[] = 'Composition: the face and the shoulders are the subject, large, sharp and in the middle of the frame, the eyes readable. Draw the person exactly as in their picture: face, hair, headwear and clothing, one layer.';
        } elseif ($closeUp) {
            $lines[] = 'Composition: the object and the hands are the subject, large, sharp and in the middle of the frame. Draw only the part of the people the frame shows: their clothing exactly as in their pictures, one layer, never a second jacket or shirt.';
        } elseif ($shot->kindOrScene() === ShotKind::MEDIUM) {
            $lines[] = 'Composition: the person from the waist up is the subject, their face and both hands readable, with the object they act on in the lower half of the frame, large and clear. The frame is cut at the waist; never the whole figure. Keep the background simple and subdued: fewer details, softer and lower in contrast than the subject, only enough to show where it is.';
            $lines[] = 'Staging: the person is inside the place, in front of a calm part of it that already belongs there; never put a separate wall, panel or backdrop in front of the place. Nothing crosses or touches them: no railings, pillars, poles, barriers or machines directly behind or in front of them.';
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

        if ($references->master !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is the master plate of this sequence, {$references->masterLabel}: the place as it really is. Draw the walls, the floor, the doors, the signs, the fixed objects and the light exactly like it, seen from this keyframe's camera; never copy its framing or its people. Where it and any other attached image differ about the place, the master plate is right.";
        }

        if ($references->setting !== null) {
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is {$references->settingLabel}. " . match (true) {
                $shot->isInsert() => 'This keyframe plays in exactly that place: keep its walls, surfaces, objects, colours and light, without its people. The camera is this keyframe\'s own, close on the one thing.',
                $references->settingIsMoment => 'This keyframe shows exactly that instant from another camera position, as a match cut: everyone stands, bends, looks and holds exactly as they do there, with the same hand positions, the same objects in the same state and the same clothing. '
                    . ($references->master !== null ? 'Take only the people and the objects from it: the place and the light come from the master plate. ' : 'Keep its place and light. ')
                    . 'Keep the screen direction: whoever is on the left there is on the left here. Only the camera changes: frame it as the framing says, and never copy that image\'s framing.',
                default => "This keyframe plays in exactly that place: keep its furniture, surfaces, objects, colours and light, and the people's clothing. Keep the screen direction: whoever is on the left there is on the left here, and comes in from the same side. Move the camera as the framing says; never copy that image's framing.",
            };
        }

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            if ($element->type === ElementType::PLACE) {
                $lines[] = 'The ' . $ordinals[$attached++] . " attached image shows what {$element->name} looks like: its buildings, shapes, colours and materials. Use it for the look of the place, not for the viewpoint: the camera stands inside the place at the spot described, at eye level, so only part of it shows.";

                continue;
            }

            $lines[] = $closeUp && ! $face && $element->type === ElementType::PERSON
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
     * The prompt for the end frame: an edit of the start frame, which is
     * attached first, so the place, the camera and the style cannot shift.
     * Only the people, their poses and the objects they handle change.
     *
     * @param  array{title: string, description: string, spatial?: string|null}  $keyframe
     */
    private static function onFirstKeyframe(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $castNames = implode(' and ', $references->castNames);

        $lines = [
            'Edit the first attached image. It is the start frame of this shot: the place, seen from a camera that does not move. This is the end frame, the result of the act.',
            // The place stays put; what the story changes in it, such as a door that is shut by now, follows the story.
            'Change only what this keyframe needs: the people, their poses and positions, and the objects they handle. The place itself stays exactly as it is in the first image: the walls, the floor and every line on it, signs, windows, fixed machines and the background, at the same place, size and angle, with the same framing, camera, light and style.',
            'The framing is the same crop at the same distance as the first image: never zoom out to show more of the people or the place, and never zoom in. A face or a body that is outside the first image stays outside.',
            'The state of things follows the story: open or closed, on or off, full or empty, smoke or none, held or in its place, as this keyframe shows, even when the first image or a picture shows it otherwise. Whatever is picked up or taken out leaves its spot empty: a handset lifted off its hook leaves the cradle empty, and the object is never drawn twice.',
            match (true) {
                $references->firstShowsCast => 'The people already in the first image move and change pose as described; never add a second copy of anyone.',
                // A close-up shows the hands at work on the surface; the people's sleeves and gloves show who they are.
                $shot->kindOrScene() === ShotKind::CLOSE_UP && ! $shot->showsFace() => "Add the hands and forearms of {$castNames} into it, reaching in from the edge of the frame at the size this close framing gives, with their sleeves, gloves and cuffs exactly as in their pictures; their faces stay out of the frame.",
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

        $lines[] = TextRules::forImages($shot->project) . ' Signs and markings already in the first image stay where they are.';

        return implode("\n", $lines);
    }

    /**
     * The prompt for the start frame of a shot that returns to the camera of
     * an earlier shot: an edit of that camera's last frame, so the place and
     * the framing cannot shift, with the people in the poses of the end frame
     * of the shot before, which is attached second.
     *
     * @param  array{title: string, description: string, spatial?: string|null}  $keyframe
     */
    private static function backToView(Shot $shot, array $keyframe, KeyframeReferences $references): string
    {
        $lines = [
            "Edit the first attached image. It is {$references->viewLabel}: this shot returns to that camera. This is the start frame of the shot.",
            'The place stays exactly as it is in the first image: the walls, the floor and every line on it, doors, signs, windows, fixed objects and the background, at the same place, size and angle, with the same framing, camera, light and style. The framing is the same crop at the same distance: never zoom in or out.',
            $references->setting !== null
                ? "The second attached image is {$references->settingLabel}: the instant this keyframe continues, seen from another camera. Put the people and the objects in exactly that instant: where they stand relative to the objects, how they bend, look and hold their hands, and what they hold, in the same state. The people already in the first image move to those poses; whoever this keyframe does not name leaves the frame, with the place behind them shown as it is; never add a second copy of anyone. Keep the screen direction of both images."
                : 'The people and the objects stay exactly as they are in the first image.',
            '',
            'This keyframe shows: ' . Keyframe::joined($keyframe['description'], $keyframe['spatial'] ?? null),
            '',
        ];

        $ordinals = $references->setting !== null ? ['third', 'fourth', 'fifth', 'sixth', 'seventh'] : ['second', 'third', 'fourth', 'fifth', 'sixth'];
        $attached = 0;

        foreach ($references->elementImages as $entry) {
            $element = $entry['element'];
            $lines[] = 'The ' . $ordinals[$attached++] . " attached image is the picture of {$element->name} ({$element->type->value}). Draw {$element->name} exactly like it: same shape, proportions, colours and details" . ($element->type === ElementType::PERSON ? ', same face, hair, build, clothing and headwear' : '') . '. It decides how it looks, not its state or pose. Do not copy its background or pose.';
        }

        if (($rules = $shot->project->rulesBrief()) !== '') {
            $lines[] = "Project rules, always follow them:\n{$rules}";
        }

        $lines[] = TextRules::forImages($shot->project) . ' Signs and markings already in the first image stay where they are.';

        return implode("\n", $lines);
    }

    /**
     * The end frame is drawn on top of the start frame.
     */
    public static function usesFirstKeyframe(int $position): bool
    {
        return $position > 1;
    }

    /**
     * A staging direction per option of the start frame, so the options differ on purpose.
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
     * With `$withPreviousKeyframe` the start frame is attached second, as context;
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
                ? 'The second attached image is the start frame of the same shot. Use it to see how the character and the objects look and where they are, and copy them from it when the change asks for something that is missing. Do not copy its pose or framing.'
                : null,
            // At most two logos follow, so the ordinals always reach.
            ...array_map(fn(string $brand) => 'The ' . $ordinals[$attached++] . " attached image is the {$brand} logo.", $logos),
            ($logos === [] ? TextRules::forEdits($project) : TextRules::withLogos($logos)) . ' Signs and markings already in the image stay where they are.',
        ]));
    }
}
