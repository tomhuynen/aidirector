<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Ai\Bench\Benchmarks;
use App\Ai\Bench\BenchReport;
use App\Ai\Bench\BenchRunner;
use App\Ai\Bench\BenchVariant;
use App\Ai\Bench\CostEstimate;
use App\Ai\Bench\ModelPrices;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AiBench extends Command
{
    /**
     * Reasoning efforts OpenRouter accepts, plus "default" to send none.
     */
    private const array EFFORTS = ['default', 'none', 'minimal', 'low', 'medium', 'high'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:bench
        {benchmarks?* : The benchmarks to run, or "all"; leave empty to list them}
        {--model=* : Models to compare, as OpenRouter slugs; defaults to the model the app uses}
        {--effort=* : Reasoning efforts to compare: default, none, minimal, low, medium or high; defaults to the configured one}
        {--runs=1 : How often every model answers every case}
        {--limit=3 : Cases per benchmark, 0 for all}
        {--concurrency=8 : Calls running at the same time}
        {--judge=* : Judge models; defaults to pipeline.bench.judges}
        {--no-judge : Measure speed and cost only}
        {--tenant= : Tenant to read the projects from; defaults to the first}
        {--dry-run : Show the cases and an estimate of the cost without calling any model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare models and reasoning efforts on the AI steps of the app, using real projects.';

    /**
     * Estimated dollars of all benchmarks in a dry run.
     */
    private float $estimatedTotal = 0;

    public function handle(Benchmarks $benchmarks, ModelPrices $prices, CostEstimate $estimate): int
    {
        $tenant = $this->option('tenant') ? Tenant::query()->findOrFail($this->option('tenant')) : Tenant::current() ?? Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $names = $this->argument('benchmarks');

        if ($names === []) {
            $this->listBenchmarks($benchmarks);

            return self::SUCCESS;
        }

        $efforts = $this->option('effort');

        if ($invalid = array_diff($efforts, self::EFFORTS)) {
            $this->components->error('Unknown effort: ' . implode(', ', $invalid) . '. Use ' . implode(', ', self::EFFORTS) . '.');

            return self::FAILURE;
        }

        $selected = in_array('all', $names, true) ? $benchmarks->all() : collect($names)->map(fn(string $name) => $benchmarks->find($name));
        $judges = $this->option('no-judge') ? [] : ($this->option('judge') ?: Config::get('pipeline.bench.judges'));

        foreach ($selected as $benchmark) {
            $this->runBenchmark($benchmark, $tenant, $judges, $prices, $estimate);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Estimated total</>', '<options=bold>$' . number_format($this->estimatedTotal, 2) . '</>');
            $this->components->info('Nothing was sent to a model. Answer and thinking tokens are typical for GPT-5.5; heavy-thinking models such as Grok or DeepSeek can cost several times more.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $judges
     */
    private function runBenchmark(Benchmark $benchmark, Tenant $tenant, array $judges, ModelPrices $prices, CostEstimate $estimate): void
    {
        $limit = (int) $this->option('limit');
        $cases = $limit > 0 ? $benchmark->cases()->take($limit) : $benchmark->cases();

        $this->newLine();
        $this->components->twoColumnDetail("<fg=cyan;options=bold>{$benchmark->name()}</>", $benchmark->description());

        if ($cases->isEmpty()) {
            $this->components->warn('No cases: this tenant has no data for this step yet.');

            return;
        }

        $variants = $this->variants($benchmark);

        if ($this->option('dry-run')) {
            $this->estimatedTotal += $this->estimate($benchmark, $cases, $variants, $judges, $estimate);

            return;
        }

        $tasks = $this->tasks($cases, $variants);

        $this->components->info(sprintf('%d cases × %d variants × %d runs = %d calls%s.', $cases->count(), count($variants), (int) $this->option('runs'), $tasks->count(), $judges === [] ? '' : ', each scored by ' . count($judges) . ' judges'));

        $results = $this->runTasks($benchmark, $tasks, $tenant, $judges)
            ->map(fn(array $result) => [...$result, 'cost' => $result['usage'] === null ? null : $prices->cost($result['model'], $result['usage'])])
            ->all();

        $report = new BenchReport($benchmark, $variants, $results, $judges);

        $this->summaryTable($report);

        foreach (collect($results)->whereNotNull('error') as $failed) {
            $this->components->warn("{$failed['model']} on {$failed['label']}: " . str($failed['error'])->limit(160));
        }

        $path = 'bench/' . now()->format('Y-m-d-His') . "-{$benchmark->name()}.json";
        Storage::disk('local')->put($path, (string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->components->twoColumnDetail('Full report with every answer', Storage::disk('local')->path($path));
    }

    /**
     * Every model with every effort.
     *
     * @return list<BenchVariant>
     */
    private function variants(Benchmark $benchmark): array
    {
        $models = $this->option('model') ?: [$benchmark->model()];
        $efforts = $this->option('effort') ?: [null];

        return collect($models)
            ->crossJoin($efforts)
            ->map(fn(array $pair) => new BenchVariant($pair[0], $pair[1]))
            ->all();
    }

    /**
     * @param  Collection<int, BenchCase>  $cases
     * @param  list<BenchVariant>  $variants
     * @return Collection<int, array{case: BenchCase, variant: BenchVariant}>
     */
    private function tasks(Collection $cases, array $variants): Collection
    {
        $tasks = [];

        foreach (range(1, max(1, (int) $this->option('runs'))) as $run) {
            foreach ($cases as $case) {
                foreach ($variants as $variant) {
                    $tasks[] = ['case' => $case, 'variant' => $variant];
                }
            }
        }

        return collect($tasks);
    }

    /**
     * Runs the calls in batches, each call in its own process.
     *
     * @param  Collection<int, array{case: BenchCase, variant: BenchVariant}>  $tasks
     * @param  list<string>  $judges
     * @return Collection<int, array<string, mixed>>
     */
    private function runTasks(Benchmark $benchmark, Collection $tasks, Tenant $tenant, array $judges): Collection
    {
        $progress = $this->output->createProgressBar($tasks->count());
        $progress->start();
        $timeout = BenchRunner::TIMEOUT * (1 + count($judges)) + 60;

        $results = $tasks->chunk(max(1, (int) $this->option('concurrency')))->flatMap(function (Collection $batch) use ($benchmark, $tenant, $judges, $timeout, $progress) {
            try {
                $results = Concurrency::run(
                    $batch->map(fn(array $task) => BenchRunner::task((int) $tenant->getKey(), $benchmark->name(), $task['case']->key, $task['variant'], $judges))->all(),
                    $timeout,
                );
            } catch (Throwable $exception) {
                $results = $batch->map(fn(array $task) => [
                    'case' => $task['case']->key,
                    'label' => $task['case']->label,
                    'model' => $task['variant']->model,
                    'effort' => $task['variant']->effort,
                    'seconds' => null,
                    'usage' => null,
                    'output' => null,
                    'judgements' => [],
                    'error' => $exception->getMessage(),
                ])->all();
            }

            $progress->advance($batch->count());

            return array_values($results);
        });

        $progress->finish();
        $this->newLine(2);

        return $results->values();
    }

    /**
     * Shows what every variant would cost and returns the total.
     *
     * @param  Collection<int, BenchCase>  $cases
     * @param  list<BenchVariant>  $variants
     * @param  list<string>  $judges
     */
    private function estimate(Benchmark $benchmark, Collection $cases, array $variants, array $judges, CostEstimate $estimate): float
    {
        $runs = max(1, (int) $this->option('runs'));

        $rows = collect($variants)->map(function (BenchVariant $variant) use ($benchmark, $cases, $judges, $estimate, $runs) {
            $costs = $cases->map(fn(BenchCase $case) => $estimate->forCase($benchmark, $case, $variant, $judges));

            return [
                'variant' => $variant->label(),
                'calls' => $cases->count() * $runs,
                'answers' => $costs->sum('call') * $runs,
                'judging' => $costs->sum('judging') * $runs,
                'unknown' => $costs->contains(fn(array $cost) => $cost['call'] === null || ($judges !== [] && $cost['judging'] === null)),
            ];
        });

        $this->components->info(sprintf('%d cases: %s.', $cases->count(), $cases->pluck('label')->join(', ')));

        $this->table(
            ['Variant', 'Calls', 'Answers', 'Judging', 'Total'],
            $rows->map(fn(array $row) => [
                $row['variant'] . ($row['unknown'] ? ' <fg=red>(model not on OpenRouter)</>' : ''),
                $row['calls'],
                '$' . number_format($row['answers'], 4),
                $judges === [] ? '–' : '$' . number_format($row['judging'], 4),
                '$' . number_format($row['answers'] + $row['judging'], 4),
            ])->all(),
        );

        return (float) $rows->sum(fn(array $row) => $row['answers'] + $row['judging']);
    }

    private function summaryTable(BenchReport $report): void
    {
        $criteria = array_keys($report->benchmark->criteria());
        $judged = $report->judges !== [];

        $this->table(
            ['Variant', 'OK', 'Median', 'Slowest', 'Output tok', 'Thinking tok', 'Cost', ...($judged ? ['Quality', ...$criteria] : [])],
            collect($report->summary())->map(fn(array $row) => [
                $row['variant'],
                $row['ok'],
                $row['median'] === null ? '–' : "{$row['median']} s",
                $row['slowest'] === null ? '–' : "{$row['slowest']} s",
                $row['output_tokens'] ?? '–',
                $row['reasoning_tokens'] ?? '–',
                $row['cost'] === null ? '–' : '$' . number_format($row['cost'], 4),
                ...($judged ? [$row['quality'] ?? '–', ...array_map(fn(?float $score) => $score ?? '–', array_values($row['scores']))] : []),
            ])->all(),
        );
    }

    private function listBenchmarks(Benchmarks $benchmarks): void
    {
        $this->table(
            ['Benchmark', 'What it runs', 'Cases'],
            $benchmarks->all()->map(fn(Benchmark $benchmark) => [$benchmark->name(), $benchmark->description(), $benchmark->cases()->count()])->all(),
        );

        $this->line('Run one with <comment>php artisan ai:bench photo-analysis --model=openai/gpt-5.5 --model=google/gemini-3.8-flash --effort=default --effort=low</comment>');
    }
}
