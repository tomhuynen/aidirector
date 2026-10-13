<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Says where each person and thing of the first keyframe goes in the empty
 * place, as a box in the image, before anything is drawn: grey placeholders
 * are put there, so the image model never has to work out what stands in
 * front of what, such as a car on the camera side of a closed barrier.
 */
class StagingBoxer implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<array{name: string, type: string}>  $cast  who and what goes into the place
     * @param  string  $problem  what was wrong with the boxes last time, to put right
     */
    public function __construct(
        private readonly string $description,
        private readonly string $spatial,
        private readonly array $cast,
        private readonly string $problem = '',
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the empty place of a shot of an animated film, seen from its camera, and what its first keyframe shows. Say where each person and thing of the cast goes in this picture, as a box, so plain grey placeholders can be put there before the keyframe is drawn.

            - A box covers the whole person or thing as it will appear: a standing person from head to shoes, a car from roof to wheels. Its size follows the distance: smaller further from the camera, the way the place shrinks there.
            - The bottom of the box is where it stands on the ground: on the floor, the road or a pavement that is visible in the picture, never on a wall, in the sky or inside a fixed part of the place.
            - Put each one where the description says, on the side of the frame it says, and on the right side of what it must be before or behind, such as on the camera side of a closed barrier.
            - Boxes of different cast members do not overlap unless one stands partly behind the other.
            - facing: which way it faces in the picture, such as "toward the camera", "away from the camera", "to the right of the frame".
            - before: the fixed parts of the place this one stands nearer to the camera than, by their plain names as seen in the picture, such as "the striped barrier arm" or "the security booth". Empty when none matters.
            - fixed_parts: the fixed parts of the place near the boxes that must stay exactly as they are, such as the booth, the barrier arm and its post, a sign, a bollard. Plain names as seen in the picture.
            Coordinates are shares of the image width and height, from 0 at the left and top to 1 at the right and bottom.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'boxes' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'left' => $schema->number()->required(),
                'top' => $schema->number()->required(),
                'right' => $schema->number()->required(),
                'bottom' => $schema->number()->required(),
                'facing' => $schema->string()->required(),
                'before' => $schema->array()->items($schema->string())->required(),
            ]))->required(),
            'fixed_parts' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function promptFor(): string
    {
        $cast = collect($this->cast)->map(fn(array $member) => "- {$member['name']} ({$member['type']})")->join("\n");

        return "The attached image is the empty place.\n\nWhat the first keyframe shows: {$this->description}"
            . ($this->spatial !== '' ? "\nThe spatial fact it must show: {$this->spatial}" : '')
            . "\n\nThe cast to place, each with a box named exactly like this:\n{$cast}"
            . ($this->problem !== '' ? "\n\nThe boxes you gave before were wrong: {$this->problem} Put that right." : '');
    }
}
