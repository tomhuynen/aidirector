<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use App\Ai\Bench\Benchmarks\CastSuggestionsBenchmark;
use App\Ai\Bench\Benchmarks\KeyframePlanBenchmark;
use App\Ai\Bench\Benchmarks\PhotoAnalysisBenchmark;
use App\Ai\Bench\Benchmarks\StorylineOptionsBenchmark;
use App\Ai\Bench\Benchmarks\StyleOptionsBenchmark;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every benchmark `php artisan ai:bench` can run, in the order of the flow.
 */
class Benchmarks
{
    /**
     * @var list<class-string<Benchmark>>
     */
    private const array BENCHMARKS = [
        PhotoAnalysisBenchmark::class,
        StyleOptionsBenchmark::class,
        CastSuggestionsBenchmark::class,
        StorylineOptionsBenchmark::class,
        KeyframePlanBenchmark::class,
    ];

    /**
     * @return Collection<int, Benchmark>
     */
    public function all(): Collection
    {
        return collect(self::BENCHMARKS)->map(fn(string $class) => app($class));
    }

    public function find(string $name): Benchmark
    {
        return $this->all()->first(fn(Benchmark $benchmark) => $benchmark->name() === $name)
            ?? throw new InvalidArgumentException("Unknown benchmark [{$name}]. Run `php artisan ai:bench` to list them.");
    }
}
