<?php

declare(strict_types=1);

namespace App\Ai\Bench;

/**
 * A model and reasoning effort to run a benchmark with. A null effort uses
 * the effort configured for the agent, "default" leaves it to the model.
 */
final readonly class BenchVariant
{
    public function __construct(
        public string $model,
        public ?string $effort = null,
    ) {}

    public function label(): string
    {
        return $this->model . ' · ' . ($this->effort ?? 'configured');
    }
}
