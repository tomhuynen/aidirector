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
 * other and to the camera, which way they face and the routes through the
 * place, so every shot keeps them where they are and a plan can be checked
 * against them before it is drawn.
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

            map: three to six short sentences on where the fixed things stand: first from this camera, left or right of the frame and near or far, then to each other, such as "The burning waste bin stands right of the blue door, against the wall, a few steps from the quay edge. The green safe spot is further along the quay towards the ship, about ten steps from the bin." Name the doors, signs, bins, machines, markings and the edge of the place, and which way open space and exits lie. Use the given names for the things that have one. Only what is there; no people, no style or light.
            things: every fixed thing of the map once: its name, its side of the frame (left, middle or right), how far from the camera (near, middle or far), and which way it faces or opens, such as "its window faces the lane" or "opens towards the camera"; empty when it faces nowhere in particular.
            routes: the ways through the place that people or vehicles take, such as a lane, a path, a corridor or a door: its name and how it runs in the frame, such as "from the near middle away from the camera to the far left" or "across the frame from left to right"; and what stands along it. Empty when there is none.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'map' => $schema->string()->required(),
            'things' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'side' => $schema->string()->enum(['left', 'middle', 'right'])->required(),
                'distance' => $schema->string()->enum(['near', 'middle', 'far'])->required(),
                'faces' => $schema->string()->required(),
            ]))->max(12)->required(),
            'routes' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'runs' => $schema->string()->required(),
            ]))->max(6)->required(),
        ];
    }

    public function promptFor(): string
    {
        return "The attached image is {$this->place}, empty." . ($this->things === [] ? '' : "\nThings with a name here: " . implode(', ', $this->things) . '.');
    }
}
