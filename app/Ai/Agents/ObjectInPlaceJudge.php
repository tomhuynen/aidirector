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
 * Says whether an object of the cast and sets is in an empty place at all.
 * The object finder always finds something, so an object that is only
 * carried in, such as a badge in someone's hand, would be found as some
 * other thing in the place that merely looks alike.
 */
class ObjectInPlaceJudge implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(private readonly Element $element) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get two images: first an empty place from an animated film, then the picture of one object. Say whether that object is in the place: the same kind of thing, drawn there, in any state, such as a door open or closed or a phone on or off its hook.
            Something else that only looks a little alike, such as a green wall cabinet for a badge holder or a box for a phone, does not count. When in doubt, it is not there.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'present' => $schema->boolean()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "Is {$this->element->name} in the place? It is: {$this->element->description}";
    }
}
