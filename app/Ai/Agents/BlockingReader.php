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
 * Reads where the cast stands in the master of a place: which side of the
 * frame, how near, which way they face and what they stand beside. Every shot
 * cut from the master keeps it, so nobody switches sides between shots.
 */
class BlockingReader implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $cast  the names of the people and things in the picture
     */
    public function __construct(private readonly array $cast) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the master picture of a place in an animated film, with its cast where they stand at the start of a sequence of shots. Every shot of the sequence is cut from it, so where each stands must be kept in all of them.
            blocking: one short sentence per person or thing of the cast, by its name: which side of the frame it is on (left, middle or right), near or far, which way it faces from the camera's point of view, and what it stands beside or in, such as "The Security officer stands on the right of the frame by the booth window, facing left towards the lane." For a vehicle, also which way its front points along its lane. Only what the picture shows. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'blocking' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return 'The attached image is the master. Its cast: ' . implode(', ', $this->cast) . '.';
    }
}
