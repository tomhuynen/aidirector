<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
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
 * The full check also judges the people, objects, lettering and the point of
 * the keyframe. The place check looks only at the place, for the model that
 * sees shifted floor lines and backgrounds best.
 */
class KeyframeChecker implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public const FULL = 'full';

    public const PLACE = 'place';

    /**
     * @param  list<string>  $roles  what each attached image is, in order; the last is the keyframe to check
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $roles,
        private readonly ?string $mustShow = null,
        private readonly string $scope = self::FULL,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->scope === self::PLACE ? $this->placeInstructions() : $this->fullInstructions();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $categories = $this->scope === self::PLACE ? ['place', 'lettering'] : ['person', 'object', 'state', 'place', 'lettering'];

        return [
            'inventory' => $schema->array()->items($schema->string())->required(),
            'issues' => $schema->array()->items($schema->object([
                'category' => $schema->string()->enum($categories)->required(),
                'change' => $schema->string()->required(),
                'severity' => $schema->string()->enum(['high', 'low'])->required(),
            ]))->required(),
            'must_show_visible' => $schema->boolean()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "What the keyframe to check should show: {$this->keyframe->description}";
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

    private function fullInstructions(): string
    {
        return <<<INSTRUCTIONS
            You check the continuity of a keyframe of an animated e-learning film against its references. The camera stands still: the place does not move between keyframes.

            {$this->imageList()}

            The shot teaches: {$this->keyframe->shot->takeaway}

            Work in three steps, using each reference only for what it is the reference for.
            1. Inventory of the references. List what must stay the same: each person (face, hair, headwear and any lettering on it, glasses, clothing and colours), each object and its state (what it holds, what is in it, where it hangs or stands), and the fixed parts of the place with where they are: floor markings and painted lines (their position and angle), doors, signs, windows, machines, vehicles and any lettering on them.
            2. Compare the keyframe to check with that inventory: what disappeared, what changed in look, position, shape, angle or state.
            3. Look the other way: what is in the keyframe to check that is in none of the references, such as an extra object, person, lettering, logo or numbers. A person who is in the keyframe to check but not in keyframe 1 is fine when the description has them; compare them with their own reference.

            Allowed changes, never report these: pose, gestures, where a person stands or looks, facial expression, and the change the description asks for. Everything else that changes is an issue.

            Direction: when the description says which way someone faces or moves, such as back to the camera, walking away from the camera or into the place behind a door, check it. Someone who faces the camera or walks towards it while the description says they go away from it or into the place is a high issue in category state.

            Severity:
            - high: a viewer notices it at a glance, or it makes the story wrong, such as a different helmet, glasses that appear or disappear, an object that is both in a hand and in its box, lettering that appears, or a floor line that runs elsewhere.
            - low: you only see it when you look for it.

            Must show: {$this->mustShowLine()}

            {$this->projectRules()}

            Output:
            - inventory: short lines from step 1.
            - issues: each issue with category (person, object, state, place, lettering), what changed in one plain sentence for the director, as you would say it to a colleague, and severity. Empty when nothing changed that should not.
            - must_show_visible: true when the must show is clearly visible, or when there is none.
            Write in English.
            INSTRUCTIONS;
    }

    private function placeInstructions(): string
    {
        return <<<'INSTRUCTIONS'
            You check whether the place stays the same between two keyframes of an animated e-learning film. The first image is keyframe 1 of the shot, the reference; it may show the place without people. The second image is a later keyframe of the same shot. The camera stands still, so the place must not move at all; people and the objects they handle may.

            Work in three steps.
            1. Inventory of the place in the reference, with where each part is: floor markings and painted lines (where they start and end and at what angle), doors and door frames, walls, windows and what is seen through them, signs, bins, machines, vehicles, ships, and any lettering, logos or numbers on them.
            2. Compare the later keyframe with that inventory: what moved, changed shape, angle or size, or disappeared.
            3. Look the other way: what is part of the place in the later keyframe but not in the reference, such as new lettering, numbers or objects.

            Ignore the people, what they hold and what they do. Ignore the objects the story moves or changes too, such as something put into or taken out of a bin or box, something picked up, put down or dropped, a door opened or closed: what the keyframe should show tells you what happens, and the keyframe before it may already have done it. Only the fixed place counts, where things stand and how they look, not what is in them.

            Severity:
            - high: a viewer of the animation would see the place jump, such as a floor line that runs elsewhere, a door frame that changes width, a sign that moves, or lettering that appears.
            - low: you only see it when you look for it.

            Output:
            - inventory: short lines from step 1.
            - issues: each change with category (place or lettering), what changed in one plain sentence for the director, and severity. Empty when the place stays the same.
            - must_show_visible: always true.
            Write in English.
            INSTRUCTIONS;
    }

    private function mustShowLine(): string
    {
        return filled($this->mustShow)
            ? "{$this->mustShow} This is what the keyframe is for; if a viewer cannot see it at a glance, set must_show_visible to false and add it as a high issue in category state."
            : 'nothing specific for this keyframe.';
    }

    private function projectRules(): string
    {
        $rules = $this->keyframe->shot->project->rulesBrief();
        $shotRules = $this->keyframe->shot->rulesBrief();

        return implode("\n", array_filter([
            $rules === '' ? null : "Also check the rules the director confirmed for this project; breaking one is a high issue:\n{$rules}",
            $shotRules === '' ? null : "Also check the rules the director set for this shot; breaking one is a high issue, named with the rule:\n{$shotRules}",
        ]));
    }
}
