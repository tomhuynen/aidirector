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
 * Writes the narration for one shot: what a calm voice says while the clip
 * plays, short enough to fit its length.
 */
class VoiceOverWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
        private readonly int $maxWords,
    ) {}

    public function instructions(): Stringable|string
    {
        $seconds = $this->shot->durationInSeconds();

        return <<<INSTRUCTIONS
            You write the voice-over for one shot of an animated e-learning film. A calm voice speaks it while the clip of {$seconds} seconds plays.

            - At most {$this->maxWords} words, so it fits the clip at a calm pace. Fewer is better than rushed.
            - Speak to the learner in plain, everyday words, in the present tense.
            - Land the takeaway; do not describe every movement the viewer already sees.
            - One or two short sentences. No lists, no quotes, no stage directions.
            - Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required(),
        ];
    }

    public function promptFor(?string $tooLong = null): string
    {
        $storyline = $this->shot->chosenStoryline()['storyline'] ?? '';
        $prompt = "Takeaway: {$this->shot->takeaway}\nStoryline: {$storyline}";

        return $tooLong === null
            ? $prompt
            : "{$prompt}\n\nThis was too long, shorten it to at most {$this->maxWords} words: {$tooLong}";
    }
}
