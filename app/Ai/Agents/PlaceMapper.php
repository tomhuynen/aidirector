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
 * Describes once where the fixed things of a chosen place stand, to each
 * other and to the camera, so every shot that shows another part of it, or
 * looks at it from another side, keeps them where they are.
 */
class PlaceMapper implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $things  the objects of the cast and sets that play here, to name by these names
     */
    public function __construct(private readonly string $place, private readonly array $things = []) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the empty place several shots of an animated film play in. Each shot shows its own part of it, or looks at it from another side, so the fixed things must keep where they stand to each other.

            map: three to six short sentences on where the fixed things stand: first from this camera, left or right of the frame and near or far, then to each other, such as "The burning waste bin stands right of the blue door, against the wall, a few steps from the quay edge. The green safe spot is further along the quay towards the ship, about ten steps from the bin." Name the doors, signs, bins, machines, markings and the edge of the place, and which way open space and exits lie. Use the given names for the things that have one. Only what is there; no people, no style or light. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'map' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "The attached image is {$this->place}, empty." . ($this->things === [] ? '' : "\nThings with a name here: " . implode(', ', $this->things) . '.');
    }
}
