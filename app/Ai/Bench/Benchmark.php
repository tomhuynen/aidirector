<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * One AI step of the app, run on real data from the current tenant so every
 * variant gets exactly the prompt the app would send.
 */
abstract class Benchmark
{
    /**
     * The name to run it by, such as "photo-analysis".
     */
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * @return Collection<int, BenchCase>
     */
    abstract public function cases(): Collection;

    /**
     * Runs the step for one case with one variant.
     */
    abstract public function answer(BenchCase $case, BenchVariant $variant): BenchAnswer;

    /**
     * What the judges score, criterion key => what a 5 looks like.
     *
     * @return array<string, string>
     */
    abstract public function criteria(): array;

    /**
     * The model the app uses for this step today.
     */
    public function model(): string
    {
        return (string) Config::get('pipeline.models.text');
    }

    public function case(string $key): BenchCase
    {
        return $this->cases()->firstWhere('key', $key)
            ?? throw new InvalidArgumentException("Unknown case [{$key}] for benchmark [{$this->name()}].");
    }
}
