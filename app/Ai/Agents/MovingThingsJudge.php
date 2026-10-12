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
 * Names the things of the cast that move in the shots playing at one place,
 * such as a truck that drives off later: an empty place is drawn without them,
 * and the image model draws whatever it gets a picture of, so their pictures
 * are left out.
 */
class MovingThingsJudge implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $things  the objects of the cast at this place
     * @param  list<string>  $happens  what happens there, one line per shot
     */
    public function __construct(
        private readonly array $things,
        private readonly array $happens,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get what happens at one place in the shots of an animated film, and the things of its cast there. Name the things that move in any of these shots by themselves or are driven, flown or rolled, such as a truck that drives in or off, a helicopter that lands or a forklift that drives past. Not what someone only picks up, carries, opens or holds, and not what stands still throughout, such as a parked car nobody drives.
            moving: the names exactly as given; empty when none move.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'moving' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function promptFor(): string
    {
        return 'The things: ' . implode(', ', $this->things) . "\nWhat happens there:\n" . implode("\n", array_map(fn(string $line) => "- {$line}", $this->happens));
    }
}
