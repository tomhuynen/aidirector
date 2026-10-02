<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Scores one output of an AI step for `php artisan ai:bench`, without knowing
 * which model wrote it.
 */
class BenchJudge implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  array<string, string>  $criteria  Criterion key => what a 5 looks like.
     */
    public function __construct(
        private readonly array $criteria,
    ) {}

    public function instructions(): Stringable|string
    {
        $criteria = collect($this->criteria)
            ->map(fn(string $description, string $key) => "- {$key}: {$description}")
            ->join("\n");

        return <<<INSTRUCTIONS
            You review the output of one AI step in an app that turns briefs into animated shots. You get the instructions the AI was given, its prompt, any attached images, and what it answered.

            Score each criterion from 1 to 5, where 5 is excellent. Be strict and consistent: a 5 is rare, a 3 is usable but clearly flawed.
            {$criteria}

            Then give a one-sentence note on the biggest weakness.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (array_keys($this->criteria) as $criterion) {
            $fields[$criterion] = $schema->integer()->min(1)->max(5)->required();
        }

        return [...$fields, 'note' => $schema->string()->required()];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function promptFor(string $instructions, string $prompt, array $output): string
    {
        $answer = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<REVIEW
            INSTRUCTIONS THE AI WAS GIVEN:
            {$instructions}

            PROMPT:
            {$prompt}

            ANSWER TO REVIEW:
            {$answer}
            REVIEW;
    }
}
