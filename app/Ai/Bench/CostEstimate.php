<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use App\Ai\Agents\BenchJudge;

/**
 * What a bench run would cost, worked out from the real prompts and
 * OpenRouter's prices without calling a model. Prompt tokens are counted
 * from the text; answer and reasoning tokens are typical GPT-5.5 numbers,
 * so heavy-thinking models can cost several times more.
 */
class CostEstimate
{
    /**
     * Roughly how many characters make one token in English prompts.
     */
    private const int CHARACTERS_PER_TOKEN = 4;

    /**
     * Prompt tokens for one attached photo at the reference size.
     */
    private const int TOKENS_PER_IMAGE = 1500;

    /**
     * Reasoning tokens per call by effort, as GPT-5.5 used them.
     */
    private const array REASONING_TOKENS = [
        'default' => 700,
        'high' => 1500,
        'medium' => 700,
        'low' => 50,
        'minimal' => 0,
        'none' => 0,
    ];

    /**
     * Visible tokens of one judge's scores and note.
     */
    private const int JUDGE_OUTPUT_TOKENS = 150;

    public function __construct(
        private readonly ModelPrices $prices,
    ) {}

    /**
     * The cost of one variant answering one case, and of every judge
     * scoring that answer; null when OpenRouter does not list a model.
     *
     * @param  list<string>  $judges
     * @return array{call: float|null, judging: float|null}
     */
    public function forCase(Benchmark $benchmark, BenchCase $case, BenchVariant $variant, array $judges): array
    {
        $agent = $benchmark->agent($case)->withReasoningEffort($variant->effort);
        $instructions = (string) $agent->instructions();
        $prompt = $benchmark->prompt($case);
        $images = count($benchmark->attachments($case)) * self::TOKENS_PER_IMAGE;
        $output = $benchmark->expectedOutputTokens();

        $call = $this->prices->cost($variant->model, [
            'prompt_tokens' => $this->tokens($instructions . $prompt) + $images,
            'completion_tokens' => $output + self::REASONING_TOKENS[$agent->reasoningEffort()],
        ]);

        $judge = new BenchJudge($benchmark->criteria());
        $judgeInput = $this->tokens((string) $judge->instructions() . $instructions . $prompt) + $output + $images;
        $judgeOutput = self::JUDGE_OUTPUT_TOKENS + self::REASONING_TOKENS[$judge->reasoningEffort()];

        $judging = collect($judges)->map(fn(string $model) => $this->prices->cost($model, [
            'prompt_tokens' => $judgeInput,
            'completion_tokens' => $judgeOutput,
        ]));

        return [
            'call' => $call,
            'judging' => $judging->contains(null) ? null : (float) $judging->sum(),
        ];
    }

    private function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARACTERS_PER_TOKEN);
    }
}
