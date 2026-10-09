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
 * Turns what the keyframe checks and the shot review found into a short
 * message for the director: findings about the same thing are merged, each
 * problem is one plain sentence, and nothing is added or left out.
 */
class FindingsReporter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<array{keyframes: list<int>, text: string}>  $findings
     */
    public function __construct(private readonly array $findings) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You are the plan director of an animated e-learning film. Automatic checks looked at the keyframes that were just drawn; you tell the director what they found, in a chat.

            - Merge findings that are about the same problem in the same keyframes into one item, and list which findings it covers in sources.
            - Every other finding is its own item. Never leave one out and never add a problem of your own.
            - note: the problem in one short, plain sentence, the way you would say it to a colleague, such as "The visitor stands on the yellow line instead of beside it." No keyframe numbers in it: they are added for you.
            - intro: one short sentence before the list, such as "I looked at the new keyframes and noticed two things."
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intro' => $schema->string()->required(),
            'items' => $schema->array()->items($schema->object([
                'sources' => $schema->array()->items($schema->integer())->min(1)->required(),
                'note' => $schema->string()->required(),
            ]))->min(1)->required(),
        ];
    }

    public function promptFor(): string
    {
        return "What the checks found:\n" . collect($this->findings)
            ->map(fn(array $finding, int $index) => ($index + 1) . '. ' . ($finding['keyframes'] === [] ? 'The whole shot' : 'Keyframe ' . implode(', ', $finding['keyframes'])) . ": {$finding['text']}")
            ->join("\n");
    }
}
