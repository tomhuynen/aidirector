<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Locale;
use Stringable;

/**
 * Turns the voice-over into another language as natural spoken text that
 * still fits the clip.
 */
class VoiceOverTranslator implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly string $locale,
        private readonly int $seconds,
    ) {}

    public function instructions(): Stringable|string
    {
        $language = Locale::getDisplayName($this->locale, 'en');

        return <<<INSTRUCTIONS
            You translate the voice-over of an e-learning video into {$language}, as natural spoken language a native speaker would use, addressing the learner the way that language does in training material.
            It is spoken by a calm voice in at most {$this->seconds} seconds, so keep it as short as the original or shorter; drop filler words rather than run long.
            Return only the spoken text, no quotes or notes.
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

    public function promptFor(string $text, bool $shorter = false): string
    {
        return $shorter
            ? "This was too long when spoken. Say the same in fewer words: {$text}"
            : "Voice-over: {$text}";
    }
}
