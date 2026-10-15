<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\HandRules;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads keyframe 1 after the image model drew it whole: where each of the
 * cast stands, whether they stand where the description puts them, and
 * whether they look like their picture. What it finds wrong is put right by
 * moving or redrawing only that one; the boxes it reads are the layout the
 * keyframes after it are drawn from.
 */
class KeyframeReader implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<Element>  $cast  the people and objects of the keyframe, in the order their pictures follow the keyframe
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $cast,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You check keyframe 1 of an animated e-learning film, the first attached image, against what it should show. The pictures of the cast follow it, in the order listed.

            For each of the cast, give:
            - box: where they are in the image, as top, left, bottom and right on a 0 to 1000 grid of the frame, from the top left. The box holds the whole person or vehicle tightly; its bottom is where the feet or wheels touch the floor.
            - facing: which way they face as seen from the camera, named after what they look at or turn to.
            - pose: their pose and what their hands do, in one short sentence.
            - placed_right: true when they stand where the description puts them, also in relation to other things, such as before or past a barrier, in front of or behind a booth, inside or outside a marked area. False when that relation is wrong.
            - target: when placed_right is false, the box where they should stand instead, on the floor, at the size they have there; otherwise the same as box.
            - looks_right: true when they look like their picture: the same clothing, colours, headwear and details, with nothing added that the picture does not have, such as a vest or a helmet. False when they differ.
            - problem: what is wrong in one short sentence, or empty.

            An object someone holds or carries belongs to their box; leave it out. Leave out the cast the image does not show.
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
                'placed_right' => $schema->boolean()->required(),
                'target' => $box->required(),
                'looks_right' => $schema->boolean()->required(),
                'problem' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    public function promptFor(): string
    {
        $lines = ['What keyframe 1 should show: ' . $this->keyframe->fullDescription(), '', 'The cast, with their pictures attached in this order after the keyframe:'];

        foreach ($this->cast as $element) {
            $lines[] = "- {$element->name} ({$element->type->value})";
        }

        return implode("\n", $lines);
    }
}
