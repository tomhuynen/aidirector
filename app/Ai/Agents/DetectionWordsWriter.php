<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the words an object finder knows for an object of the cast and
 * sets: its name is made up for the project, such as "Emergency call
 * station", and the finder only knows common things, such as "red wall phone".
 */
class DetectionWordsWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(private readonly Element $element) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            An object finder searches pictures for things by a short text. It only knows common, everyday words, such as "red wall phone", "fire extinguisher" or "yellow barrier". Names made up for a project, brands and abstract words make it find nothing.

            Give two ways to find the object described: first the best one, then another one in other words. Each two to four common words: what the thing is, with a colour or a material when that sets it apart. Only the object itself, never what is around it or a person using it. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'words' => $schema->array()->items($schema->string())->min(1)->max(2)->required(),
        ];
    }

    public function promptFor(): string
    {
        return "The object: {$this->element->name}. {$this->element->description}";
    }
}
