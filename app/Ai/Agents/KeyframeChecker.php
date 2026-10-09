<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Models\Keyframe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Compares a freshly drawn keyframe with keyframe 1 of its shot, the way a
 * continuity editor would: first an inventory of what keyframe 1 holds, then
 * what disappeared or changed, then what is new. Each issue gets a severity;
 * only high ones count against the keyframe.
 *
 * It judges the place, the people, objects, lettering and the point of the
 * keyframe. On a keyframe put onto its place the place is always right, so
 * there it only judges the people, the objects and the point of the keyframe.
 */
class KeyframeChecker implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $roles  what each attached image is, in order; the last is the keyframe to check
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $roles,
        /** The keyframe is put onto its place, so the place is the same picture in every keyframe. */
        private readonly bool $placeIsFixed = false,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->fullInstructions();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $categories = $this->placeIsFixed ? ['person', 'object', 'state', 'lettering'] : ['person', 'object', 'state', 'place', 'lettering'];

        return [
            'inventory' => $schema->array()->items($schema->string())->required(),
            'issues' => $schema->array()->items($schema->object([
                'category' => $schema->string()->enum($categories)->required(),
                'change' => $schema->string()->required(),
                'severity' => $schema->string()->enum(['high', 'low'])->required(),
            ]))->required(),
        ];
    }

    public function promptFor(): string
    {
        return "What the keyframe to check should show: {$this->keyframe->fullDescription()}";
    }

    /**
     * The attached images in order, each with what it is the reference for.
     */
    private function imageList(): string
    {
        return collect($this->roles)
            ->map(fn(string $role, int $index) => 'Image ' . ($index + 1) . ": {$role}.")
            ->join("\n");
    }

    /**
     * A scene keeps one place; a still of a montage has its own.
     */
    private function setting(): string
    {
        return match (true) {
            $this->keyframe->shot->isMontage() => 'This keyframe is one still of a montage: it has its own place and camera, so the place is never an issue; the people and objects must match their pictures and the image must show its description. Which way a person faces is never an issue.',
            $this->keyframe->shot->isPresenter() => 'This keyframe is the still of a presenter who speaks to the camera: one person, from the chest up, facing the camera straight on with the face large and clear, in front of a softly blurred place. The place is never an issue; the person must match their picture, and a face that is small, turned away or covered is a high issue.',
            $this->keyframe->shot->kindOrScene() === ShotKind::CLOSE_UP => 'This keyframe is a close-up: the hands and one object fill the frame, and faces may be out of it. People are known by their clothing, sleeves and gloves, and which way they face is never an issue. Clothing that differs from their picture, such as a second jacket or shirt layer, a doubled collar or another garment, is a high issue in category person. The camera stands still between keyframes.',
            $this->placeIsFixed => 'The camera stands still, and the place is pasted in from the same picture in every keyframe, so it is always right: never report the place, its walls, floor, lines, doors, signs or background. Only the people, the objects of the story and their state, lettering on people and objects, and the point of the keyframe count.',
            default => 'The camera stands still: the place does not move between keyframes.',
        };
    }

    private function fullInstructions(): string
    {
        return <<<INSTRUCTIONS
            You check the continuity of a keyframe of an animated e-learning film against its references. {$this->setting()}

            {$this->imageList()}

            The shot teaches: {$this->keyframe->shot->takeaway}

            Work in three steps, using each reference only for what it is the reference for.
            1. Inventory of the references. List what must stay the same: each person (face, hair, headwear and any lettering on it, glasses, clothing and colours), each object and its state (what it holds, what is in it, where it hangs or stands), and the fixed parts of the place with where they are: floor markings and painted lines (their position and angle), doors, signs, windows, machines, vehicles and any lettering on them.
            2. Compare the keyframe to check with that inventory: what disappeared, what changed in look, position, shape, angle or state.
            3. Look the other way: what is in the keyframe to check that is in none of the references, such as an extra object, person, lettering, logo or numbers. A person who is in the keyframe to check but not in keyframe 1 is fine when the description has them; compare them with their own reference.

            Allowed changes, never report these: pose, gestures, where a person stands or looks, facial expression, and the change the description asks for. Never report these either, because a viewer never sees them as a mistake:
            - a detail that differs from keyframe 1 or the place but looks the same as in the keyframe directly before: it does not change between these two keyframes, so it does not jump in the video;
            - an object of the cast and sets, or one the description names, that now stands where a background object was;
            - small background props that take no part in the story, such as items on a shelf, a counter or a desk.
            - an object without lettering, such as a blank card, paper or badge: objects are known by their shape, colour, size and where they are, never by text, so never ask for text to make one recognisable.
            Report what makes the story wrong, and what a viewer would see change between the keyframe directly before and this one.

            Direction: check which way someone faces or moves only when the story depends on it: someone who goes into or out of a place, or who must stand or move relative to a line, a zone, a door or a hazard. Then someone who faces the camera or walks towards it while the description says they go away from it or into the place is a high issue in category state. Otherwise a person who faces another way than the description says is not an issue.

            Severity:
            - high: it makes the story wrong, such as the action, who is there or the spatial fact, or a viewer sees it change at a glance between the keyframe directly before and this one, such as a different helmet, glasses that appear or disappear, an object that is both in a hand and in its box, lettering that appears, or a floor line that runs elsewhere.
            - low: everything else you would still mention.

            The point of the keyframe: what the description says about where people stand and what they do relative to lines, zones, doors, objects and hazards is what the keyframe is for. When a viewer cannot see that at a glance, add it as a high issue in category state.

            {$this->projectRules()}

            Output:
            - inventory: short lines from step 1.
            - issues: each issue with category (person, object, state, place, lettering), what changed in one plain sentence for the director, as you would say it to a colleague, and severity. Empty when nothing changed that should not.
            Write in English.
            INSTRUCTIONS;
    }

    private function projectRules(): string
    {
        $rules = $this->keyframe->shot->project->rulesBrief();

        return implode("\n", array_filter([
            $rules === '' ? null : "Also check the rules the director confirmed for this project; breaking one is a high issue:\n{$rules}",
        ]));
    }
}
