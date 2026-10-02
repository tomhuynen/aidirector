<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use App\Ai\Agents\BenchJudge;
use App\Models\Tenant;
use Closure;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Runs one case of a benchmark with one variant and has the judges score
 * the answer. Nothing is saved: the agents only read the tenant's data.
 */
class BenchRunner
{
    /**
     * Seconds a single model call may take before it counts as failed.
     */
    public const int TIMEOUT = 300;

    public function __construct(
        private readonly Benchmarks $benchmarks,
    ) {}

    /**
     * A task for `Concurrency::run()`, which runs it in its own process and
     * so first switches to the tenant the data lives in.
     *
     * @param  list<string>  $judges
     */
    public static function task(int $tenantId, string $benchmark, string $case, BenchVariant $variant, array $judges): Closure
    {
        return static function () use ($tenantId, $benchmark, $case, $variant, $judges): array {
            if (Tenant::current()?->getKey() !== $tenantId) {
                Tenant::query()->findOrFail($tenantId)->makeCurrent();
            }

            return app(self::class)->run($benchmark, $case, $variant, $judges);
        };
    }

    /**
     * @param  list<string>  $judges
     * @return array{case: string, label: string, model: string, effort: string|null, seconds: float|null, usage: array<string, int>|null, output: array<string, mixed>|null, judgements: array<string, array<string, mixed>>, error: string|null}
     */
    public function run(string $benchmark, string $case, BenchVariant $variant, array $judges): array
    {
        $benchmark = $this->benchmarks->find($benchmark);
        $case = $benchmark->case($case);

        $result = [
            'case' => $case->key,
            'label' => $case->label,
            'model' => $variant->model,
            'effort' => $variant->effort,
            'seconds' => null,
            'usage' => null,
            'output' => null,
            'judgements' => [],
            'error' => null,
        ];

        $agent = $benchmark->agent($case)->withReasoningEffort($variant->effort);
        $prompt = $benchmark->prompt($case);
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($prompt, attachments: $benchmark->attachments($case), provider: 'openrouter', model: $variant->model, timeout: self::TIMEOUT);
        } catch (Throwable $exception) {
            return [...$result, 'seconds' => $this->secondsSince($started), 'error' => $exception->getMessage()];
        }

        $result['seconds'] = $this->secondsSince($started);
        $result['usage'] = $response->usage->toArray();
        $result['output'] = $response->toArray();

        foreach ($judges as $judge) {
            $result['judgements'][$judge] = $this->judge($benchmark, $case, (string) $agent->instructions(), $prompt, $result['output'], $judge);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function judge(Benchmark $benchmark, BenchCase $case, string $instructions, string $prompt, array $output, string $model): array
    {
        $judge = new BenchJudge($benchmark->criteria());

        try {
            /** @var StructuredAgentResponse $response */
            $response = $judge->prompt($judge->promptFor($instructions, $prompt, $output), attachments: $benchmark->attachments($case), provider: 'openrouter', model: $model, timeout: self::TIMEOUT);

            return $response->toArray();
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    private function secondsSince(int $started): float
    {
        return round((hrtime(true) - $started) / 1e9, 1);
    }
}
