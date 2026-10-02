<?php

declare(strict_types=1);

namespace App\Ai\Agents\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Sends the reasoning effort configured for this agent, keyed by its class
 * name in snake case (`StorylineWriter` reads `storyline_writer`).
 */
trait SetsReasoningEffort
{
    private ?string $reasoningEffort = null;

    public function withReasoningEffort(?string $effort): static
    {
        $this->reasoningEffort = $effort;

        return $this;
    }

    public function reasoningEffort(): string
    {
        $effort = $this->reasoningEffort ?? Config::get('pipeline.reasoning_effort.' . Str::snake(class_basename(static::class)));

        return blank($effort) ? 'default' : (string) $effort;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->reasoningEffort();

        return $effort === 'default' ? [] : ['reasoning' => ['effort' => $effort]];
    }
}
