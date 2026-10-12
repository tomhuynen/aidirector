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
 * Says whether a change to an empty place shows in its new version, such as a
 * barrier arm that is raised now: the edit model sometimes leaves it as it was,
 * and every keyframe from there on would be drawn on the old state.
 */
class PlaceChangeChecker implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(private readonly string $change) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get two images of the same empty place from an animated film: first how it was, then its new version. Say whether the new version shows the change it was asked for, clearly enough that a viewer sees it at a glance, such as a barrier arm that now stands up instead of across the road. A change that is only half made, or that the first image already showed, does not count.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shown' => $schema->boolean()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "The change asked for: {$this->change}";
    }
}
