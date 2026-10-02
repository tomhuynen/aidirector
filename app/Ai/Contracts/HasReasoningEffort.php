<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use Laravel\Ai\Contracts\HasProviderOptions;

/**
 * An agent whose reasoning effort comes from `pipeline.reasoning_effort` and
 * can be overridden per call, as the AI bench does.
 */
interface HasReasoningEffort extends HasProviderOptions
{
    /**
     * Use this effort instead of the configured one: none, minimal, low,
     * medium or high, or "default" to leave it to the model.
     */
    public function withReasoningEffort(?string $effort): static;

    /**
     * The effort this agent asks for, "default" when it leaves it to the model.
     */
    public function reasoningEffort(): string;
}
