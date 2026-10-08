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
 * Keeps a shot's storyline true to a keyframe whose description changed:
 * only the part about that moment changes, so the review and the video
 * prompt read one story.
 */
class StorylineFollower implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly string $storyline,
        private readonly int $position,
        private readonly string $before,
        private readonly string $after,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            The description of one keyframe of an animated shot changed. Rewrite the shot's storyline so it matches the new description, changing as little as possible: only the part about this moment, such as "places the permit against the glass" becoming "places the permit on the dashboard". Every other word stays the same. Give the same text when the storyline already fits.
            - storyline: the storyline, in the same language and style.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'storyline' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "Storyline: {$this->storyline}\n\nKeyframe {$this->position} before: {$this->before}\n\nKeyframe {$this->position} now: {$this->after}";
    }
}
