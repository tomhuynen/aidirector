<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use Illuminate\Support\Collection;

/**
 * The results of one benchmark run, summed up per variant.
 */
class BenchReport
{
    /**
     * @param  list<BenchVariant>  $variants
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $judges
     */
    public function __construct(
        public readonly Benchmark $benchmark,
        public readonly array $variants,
        public readonly array $results,
        public readonly array $judges,
    ) {}

    /**
     * One row per variant: speed, tokens, cost and the judges' average per
     * criterion; failed calls count in "ok" only.
     *
     * @return list<array{variant: string, ok: string, median: float|null, slowest: float|null, output_tokens: int|null, reasoning_tokens: int|null, cost: float|null, quality: float|null, scores: array<string, float|null>}>
     */
    public function summary(): array
    {
        return collect($this->variants)->map(function (BenchVariant $variant) {
            $runs = collect($this->results)->filter(fn(array $result) => $result['model'] === $variant->model && $result['effort'] === $variant->effort);
            $answered = $runs->whereNull('error');
            $judgements = $answered->flatMap(fn(array $result) => collect($result['judgements'])->reject(fn(array $judgement) => isset($judgement['error'])))->values();

            $scores = [];

            foreach (array_keys($this->benchmark->criteria()) as $criterion) {
                $scores[$criterion] = $this->average($judgements->pluck($criterion));
            }

            return [
                'variant' => $variant->label(),
                'ok' => "{$answered->count()}/{$runs->count()}",
                'median' => $answered->isEmpty() ? null : round((float) $answered->median('seconds'), 1),
                'slowest' => $answered->isEmpty() ? null : (float) $answered->max('seconds'),
                'output_tokens' => $answered->isEmpty() ? null : (int) round((float) $answered->avg('usage.completion_tokens')),
                'reasoning_tokens' => $answered->isEmpty() ? null : (int) round((float) $answered->avg('usage.reasoning_tokens')),
                'cost' => $answered->whereNotNull('cost')->isEmpty() ? null : (float) $answered->avg('cost'),
                'quality' => $this->average(collect($scores)->filter()),
                'scores' => $scores,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'benchmark' => $this->benchmark->name(),
            'description' => $this->benchmark->description(),
            'ran_at' => now()->toIso8601String(),
            'judges' => $this->judges,
            'criteria' => $this->benchmark->criteria(),
            'summary' => $this->summary(),
            'results' => $this->results,
        ];
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     */
    private function average(Collection $values): ?float
    {
        $numbers = $values->filter(fn(mixed $value) => is_numeric($value));

        return $numbers->isEmpty() ? null : round((float) $numbers->avg(), 2);
    }
}
