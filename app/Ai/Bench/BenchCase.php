<?php

declare(strict_types=1);

namespace App\Ai\Bench;

/**
 * One input a benchmark runs every variant on, such as one shot or one
 * photo. The key finds the case again in the process that runs it.
 */
final readonly class BenchCase
{
    /**
     * @param  array<string, int|string|null>  $data
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $data = [],
    ) {}
}
