<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Times a shot again for its keyframes as they are now, with the rules of
 * thumb the planner uses, once a keyframe is added, deleted or described
 * differently.
 */
class ShotTimer implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(private readonly Shot $shot) {}

    public function instructions(): Stringable|string
    {
        $rules = StorylineWriter::timingRules();

        return <<<INSTRUCTIONS
            You time one shot of an animated e-learning film from its keyframes: how long the animation takes to go from the first keyframe to the last.
            {$rules}
            - seconds: the length of the shot.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seconds' => $schema->integer()->required(),
        ];
    }

    public function promptFor(): string
    {
        $keyframes = collect($this->shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['title']}: " . Keyframe::joined((string) $keyframe['description'], $keyframe['spatial'] ?? null))
            ->join("\n");

        return "Kind of shot: {$this->shot->kindOrScene()->value}\n\nKeyframes:\n{$keyframes}";
    }
}
