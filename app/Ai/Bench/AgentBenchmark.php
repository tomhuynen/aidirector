<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use App\Ai\Contracts\HasReasoningEffort;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * A step the app runs with an agent that answers in structured text.
 */
abstract class AgentBenchmark extends Benchmark
{
    abstract public function agent(BenchCase $case): Agent&HasReasoningEffort;

    abstract public function prompt(BenchCase $case): string;

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
     * Visible answer tokens one call writes, without reasoning, as measured
     * with GPT-5.5; only used to estimate the cost of a dry run.
     */
    public function expectedOutputTokens(): int
    {
        return 500;
    }

    public function answer(BenchCase $case, BenchVariant $variant): BenchAnswer
    {
        $agent = $this->agent($case)->withReasoningEffort($variant->effort);
        $prompt = $this->prompt($case);
        $attachments = $this->attachments($case);

        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($prompt, attachments: $attachments, provider: 'openrouter', model: $variant->model, timeout: BenchRunner::TIMEOUT);

        return new BenchAnswer((string) $agent->instructions(), $prompt, $response->toArray(), $response->usage->toArray(), $attachments);
    }
}
