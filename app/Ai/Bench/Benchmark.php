<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use App\Ai\Contracts\HasReasoningEffort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Image;

/**
 * One AI step of the app, run on real data from the current tenant so every
 * variant answers exactly the prompt the app would send.
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

    abstract public function agent(BenchCase $case): Agent&HasReasoningEffort;

    abstract public function prompt(BenchCase $case): string;

    /**
     * What the judges score, criterion key => what a 5 looks like.
     *
     * @return array<string, string>
     */
    abstract public function criteria(): array;

    /**
     * Images sent along with the prompt; the judges see them too.
     *
     * @return list<Image>
     */
    public function attachments(BenchCase $case): array
    {
        return [];
    }

    /**
     * The model the app uses for this step today.
     */
    public function model(): string
    {
        return (string) Config::get('pipeline.models.text');
    }

    /**
     * Visible answer tokens one call writes, without reasoning, as measured
     * with GPT-5.5; only used to estimate the cost of a dry run.
     */
    public function expectedOutputTokens(): int
    {
        return 500;
    }

    public function case(string $key): BenchCase
    {
        return $this->cases()->firstWhere('key', $key)
            ?? throw new InvalidArgumentException("Unknown case [{$key}] for benchmark [{$this->name()}].");
    }
}
