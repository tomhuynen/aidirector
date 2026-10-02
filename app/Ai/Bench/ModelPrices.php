<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * OpenRouter's current price per token for each model, to put a cost on
 * the bench runs; the SDK does not report cost for text calls.
 */
class ModelPrices
{
    /**
     * @var array<string, array{prompt: float, completion: float, cache_read: float}>|null
     */
    private ?array $prices = null;

    /**
     * The cost in dollars of one call, or null when OpenRouter does not list
     * the model.
     *
     * @param  array{prompt_tokens?: int, completion_tokens?: int, cache_read_input_tokens?: int}  $usage
     */
    public function cost(string $model, array $usage): ?float
    {
        $price = $this->prices()[$model] ?? null;

        if ($price === null) {
            return null;
        }

        $cached = $usage['cache_read_input_tokens'] ?? 0;

        return (($usage['prompt_tokens'] ?? 0) - $cached) * $price['prompt']
            + $cached * $price['cache_read']
            + ($usage['completion_tokens'] ?? 0) * $price['completion'];
    }

    /**
     * @return array<string, array{prompt: float, completion: float, cache_read: float}>
     */
    private function prices(): array
    {
        return $this->prices ??= collect(
            Http::withToken((string) Config::get('ai.providers.openrouter.key'))
                ->get(rtrim((string) Config::get('pipeline.video.url'), '/') . '/models')
                ->throw()
                ->json('data', []),
        )->mapWithKeys(fn(array $model) => [$model['id'] => [
            'prompt' => (float) ($model['pricing']['prompt'] ?? 0),
            'completion' => (float) ($model['pricing']['completion'] ?? 0),
            'cache_read' => (float) ($model['pricing']['input_cache_read'] ?? $model['pricing']['prompt'] ?? 0),
        ]])->all();
    }
}
