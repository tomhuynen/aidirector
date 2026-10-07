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
 * Reads what the director types in the plan editor and works out which
 * keyframes it touches: added, rewritten, removed or moved. It writes no
 * content, so the editor can show the new order and the frames being
 * written straight away.
 */
class PlanChangeInterpreter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<array{title: string, description: string}>  $keyframes  the plan as it is in the editor, in order
     * @param  list<string>  $rules  the rules already set for this shot
     */
    public function __construct(
        private readonly array $keyframes,
        private readonly string $storyline = '',
        private readonly array $rules = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You help a director edit the keyframe plan of one animated shot. The keyframes are numbered from 1 in their current order. Read the director's message and list the changes to the plan, in the order they should be applied; each change uses the numbers as they are after the changes before it.

            Changes:
            - insert: a new keyframe at position "at" (1 puts it first; one more than the number of keyframes puts it last). Give "instruction": what the new keyframe should show, in the director's words.
            - update: rewrite keyframe "at". Give "instruction": what should change, in the director's words.
            - remove: delete keyframe "at".
            - move: move keyframe "from" to position "at".
            Leave fields that a change does not use at 0 or empty.

            Only list what the director asks for. When the message asks no change to the keyframes, return no changes.

            A rule for the whole shot: the message may set something that must always or never happen, such as "never let her cross the red line with more than one foot" or "the crate always hangs above the zone".
            - rule: that rule as one short clear sentence in English, naming the people and things as the plan names them. Empty when the message sets no rule.
            - Check every keyframe against the rule: its description. Add an update for each keyframe that breaks it, or that would not show it the way the rule wants, with an instruction that says exactly how that keyframe follows the rule. Do not touch keyframes that already follow it.
            - storyline: when the storyline breaks the rule, the storyline rewritten so it follows it, changing nothing else; empty when it already follows it or there is no rule.
            - When the rule goes against the point of the shot, follow the rule and keep the point as close as possible, for example the mistake becomes one foot over the line instead of standing in the zone.

            reply: one short sentence back to the director saying what you are doing, in the language of the message; for a rule, name the keyframes you change, or say none break it.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'changes' => $schema->array()->items($schema->object([
                'op' => $schema->string()->enum(['insert', 'update', 'remove', 'move'])->required(),
                'at' => $schema->integer()->required(),
                'from' => $schema->integer()->required(),
                'instruction' => $schema->string()->required(),
            ]))->required(),
            'rule' => $schema->string()->required(),
            'storyline' => $schema->string()->required(),
            'reply' => $schema->string()->required(),
        ];
    }

    public function promptFor(string $message): string
    {
        $lines = collect($this->keyframes)
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['title']}: {$keyframe['description']}")
            ->join("\n");

        $rules = collect($this->rules)->map(fn(string $rule) => "- {$rule}")->join("\n");

        return implode("\n\n", array_filter([
            'Storyline: ' . ($this->storyline !== '' ? $this->storyline : '(none yet)'),
            $rules !== '' ? "Rules already set for this shot:\n{$rules}" : null,
            "The keyframes now:\n" . ($lines !== '' ? $lines : '(none yet)'),
            "The director's message: {$message}",
        ]));
    }
}
