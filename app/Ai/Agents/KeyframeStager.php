<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\HandRules;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Stages a keyframe on the image it is drawn on, the way a director blocks
 * a scene: for each of the cast in the keyframe, the box they stand in,
 * which way they face and their pose, and which things of the place change
 * state. The image model then draws exactly that, and everything else of the
 * image stays as it is.
 */
class KeyframeStager implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<Element>  $cast  the people and objects of the keyframe that have a place in the frame
     * @param  KeyframeLayout  $before  where the cast stands in the image it is drawn on
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $cast,
        private readonly KeyframeLayout $before,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You block one keyframe of an animated e-learning film. The attached image is the frame it is drawn on: the keyframe directly before it, or for the first keyframe the empty place. The camera does not move, so the new keyframe is this image with only the changes the description asks for.

            For each of the cast in the keyframe, give:
            - box: where they are in the new keyframe, as top, left, bottom and right on a 0 to 1000 grid of the frame, from the top left. The box holds the whole person or vehicle tightly. Its bottom is where the feet or wheels touch the floor, on a floor that is really there in the image, never on a wall, a counter or the sky.
            - Size: a person is as tall as people at that distance in this place would be, judged by doors, vehicles and other people in the image; further back is smaller. A car is about as tall as a person's shoulders and two to three times as long as a person is wide.
            - In front or behind: a box may overlap a thing of the place only when the person or vehicle stands in front of it or behind it in the description; then put the bottom of the box nearer to the camera (lower in the frame) when they are in front, and further away (higher) when they are behind.
            - facing: which way they face as seen from the camera, named after what they look at or turn to, such as "side-on, facing the car on the right" or "back to the camera, facing the barrier".
            - pose: their pose and what their hands do, in one short sentence.
            - changed: false when they are already in the image in exactly this spot and pose; then copy their box from where they are. True when they are new, move, turn or change pose.

            Also list the things of the place, not the cast, whose state changes in this keyframe compared with the image, such as a door that closes or a barrier arm that opens, each with its box and its new state. Leave the list empty when nothing changes.

            An object someone holds or carries belongs to their box; leave it out of the list. Leave out the cast that the keyframe does not show.
            TEXT . "\n\nIn the pose: " . HandRules::RECEIVING;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $box = $schema->object([
            'top' => $schema->integer()->required(),
            'left' => $schema->integer()->required(),
            'bottom' => $schema->integer()->required(),
            'right' => $schema->integer()->required(),
        ]);

        return [
            'cast' => $schema->array()->items($schema->object([
                'name' => $schema->string()->enum(array_map(fn(Element $element) => $element->name, $this->cast))->required(),
                'box' => $box->required(),
                'facing' => $schema->string()->required(),
                'pose' => $schema->string()->required(),
                'changed' => $schema->boolean()->required(),
            ]))->required(),
            'changes' => $schema->array()->items($schema->object([
                'thing' => $schema->string()->required(),
                'state' => $schema->string()->required(),
                'box' => $box->required(),
            ]))->required(),
        ];
    }

    public function promptFor(): string
    {
        $lines = ['What the new keyframe shows: ' . $this->keyframe->fullDescription(), '', 'The cast in it:'];

        foreach ($this->cast as $element) {
            $member = $this->before->member($element->name);
            $lines[] = "- {$element->name} ({$element->type->value}): " . ($member === null
                ? 'not in the image yet.'
                : 'in the image at [' . implode(', ', $member['box']) . "], {$member['facing']}, {$member['pose']}");
        }

        return implode("\n", $lines);
    }

    /**
     * The layout the stager answered, with only the cast it was given.
     *
     * @param  array<string, mixed>  $answer
     */
    public function layoutFrom(array $answer): KeyframeLayout
    {
        $names = array_map(fn(Element $element) => mb_strtolower($element->name), $this->cast);
        $layout = KeyframeLayout::fromArray($answer);

        return new KeyframeLayout(
            array_values(array_filter($layout->cast, fn(array $member) => in_array(mb_strtolower($member['name']), $names, true))),
            $layout->changes,
        );
    }
}
