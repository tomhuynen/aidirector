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
 * Looks at the person in a picture and says which voice fits them when
 * they speak as a presenter: male or female.
 */
class VoiceJudge implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function instructions(): Stringable|string
    {
        return 'You look at the person in the attached picture of an animated film. Say which voice fits how this person looks when they speak to the camera: male or female. When there are several people, judge the one in the middle or the largest.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'voice' => $schema->string()->enum(['male', 'female'])->required(),
        ];
    }

    public function promptFor(): string
    {
        return 'Which voice fits this person: male or female?';
    }
}
