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
 * Reads from the last keyframe of a shot what it leaves behind in its place
 * for the shots after it, such as a helicopter that has landed or a box put
 * down: where it stands and which way it faces, in terms of the place
 * itself, so a shot with another camera can put it back in the same spot.
 */
class LeftBehindReader implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $things  the objects of the cast and sets in the shot, to name by these names
     */
    public function __construct(
        private readonly string $place,
        private readonly ?string $map = null,
        private readonly array $things = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the last keyframe of a shot of an animated film, and what the empty place it plays in looks like. Later shots in this place are filmed from other cameras. Say what this shot leaves behind in the place for them.

            - Only things that were not part of the empty place, or changed, and stay there: a vehicle or a helicopter that has come to rest, an object put down, a door left open. Not the people: they move on.
            - For each, where it stands and which way it faces in terms of the place itself, never of this frame: next to or on which fixed thing, and which way its front points, such as "The Site helicopter stands on the landing circle, slightly left of its centre, its nose pointing towards the superstructure and its passenger door on the sea side."
            - things: the names of those things, by the given names when they have one; empty when nothing is left behind.
            - left: one to three sentences as above; empty when nothing is left behind.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'things' => $schema->array()->items($schema->string())->required(),
            'left' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return implode("\n", array_filter([
            "The attached image is the last keyframe of the shot, in {$this->place}.",
            $this->map !== null ? "The empty place, from its own camera: {$this->map}" : null,
            $this->things === [] ? null : 'Things with a name in this shot: ' . implode(', ', $this->things) . '.',
        ]));
    }
}
