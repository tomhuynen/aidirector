<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the keyframes the director asked for in the plan editor's chat.
 * In a drafted plan it writes them like the planner does, with the image
 * instruction and the must show. In a plan the director writes themselves
 * it only takes their words, without adding anything.
 */
class PlanFrameWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  bool  $auto  the plan was drafted for the director, so write in full
     */
    public function __construct(
        private readonly Shot $shot,
        private readonly bool $auto,
    ) {}

    public function instructions(): Stringable|string
    {
        if (! $this->auto) {
            return <<<INSTRUCTIONS
                Cast and sets of this project:
                {$this->shot->project->elementsBrief()}

                The director writes the keyframes of this shot themselves. For each keyframe you are given, write what the director's instruction says and nothing more.
                - title: two to four words naming the moment, taken from the instruction.
                - description: the director's own words for what the keyframe shows, only tidied into one or two plain sentences. Never add people, objects, poses, directions or details that the director did not give.
                - prompt: empty.
                - must_show: empty unless the director names something that must be visible.
                - elements: the exact names from the cast and sets list that the director names for this keyframe; empty otherwise.
                Write in the language the director used for the keyframes, otherwise English.
                {$this->shotRules()}
                INSTRUCTIONS;
        }

        // The same rules as the planner, so a frame added in the chat fits the drafted ones.
        $rules = (string) (new StorylineWriter($this->shot))->instructions();

        return <<<INSTRUCTIONS
            {$rules}

            Your task now is different: the plan exists, and you write only the keyframes you are given, following the director's instruction for each. Fit them into the plan: the same place, spot, camera, wording for recurring objects, and the state of things before and after them. A keyframe to rewrite keeps what it has now except what the instruction changes. For each keyframe give title, description, prompt, must_show and elements as the rules above describe.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyframes' => $schema->array()->items($schema->object([
                'position' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'prompt' => $schema->string()->required(),
                'must_show' => $schema->string()->required(),
                'elements' => $schema->array()->items($schema->string())->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  list<array{title: string, description: string, prompt?: string, mustShow?: string}>  $keyframes  the plan in its new order; new frames to write are empty
     * @param  array<int, string>  $targets  per position to write, the director's instruction
     */
    public function promptFor(string $storyline, array $keyframes, array $targets): string
    {
        // A frame being rewritten keeps what it had, so only the asked change differs.
        $plan = collect($keyframes)
            ->map(fn(array $keyframe, int $index) => ($index + 1) . '. ' . match (true) {
                ! isset($targets[$index + 1]) => "{$keyframe['title']}: {$keyframe['description']}",
                blank($keyframe['description']) => '(new, to write)',
                default => implode(' ', array_filter([
                    "(to rewrite) now: {$keyframe['title']}: {$keyframe['description']}",
                    filled($keyframe['prompt'] ?? null) && $keyframe['prompt'] !== $keyframe['description'] ? "Image instruction: {$keyframe['prompt']}" : null,
                    filled($keyframe['mustShow'] ?? null) ? "Must show: {$keyframe['mustShow']}" : null,
                ])),
            })
            ->join("\n");

        $todo = collect($targets)->map(fn(string $instruction, int $position) => "Keyframe {$position}: {$instruction}")->join("\n");

        return "Shot: {$this->shot->brief()}\nStoryline: {$storyline}\n\nThe plan:\n{$plan}\n\nWrite these keyframes:\n{$todo}";
    }

    /**
     * The rules the director set for this shot; every keyframe written follows them.
     */
    private function shotRules(): string
    {
        $rules = $this->shot->rulesBrief();

        return $rules === '' ? '' : "Rules for this shot; what you write never breaks one:\n{$rules}";
    }
}
