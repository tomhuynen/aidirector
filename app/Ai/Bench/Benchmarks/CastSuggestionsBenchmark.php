<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\ElementSuggester;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\ElementRound;
use Illuminate\Support\Collection;

/**
 * Suggesting cast and sets for every round the director confirmed in the
 * intake chat.
 */
class CastSuggestionsBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'cast-suggestions';
    }

    public function description(): string
    {
        return 'Suggest recurring people, places or objects from the brief confirmed in the chat.';
    }

    public function cases(): Collection
    {
        return ElementRound::query()->with('project')->orderBy('id')->get()->map(fn(ElementRound $round) => new BenchCase(
            key: "round-{$round->getKey()}",
            label: "{$round->project->title} · {$round->type->plural()}",
            data: ['round' => $round->getKey()],
        ));
    }

    public function agent(BenchCase $case): ElementSuggester
    {
        return new ElementSuggester(ElementRound::query()->with('project')->findOrFail($case->data['round']));
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptText();
    }

    public function expectedOutputTokens(): int
    {
        return 600;
    }

    public function criteria(): array
    {
        return [
            'brief' => "The suggestions follow the director's brief closely and cover its variety first.",
            'distinct' => 'Every suggestion is clearly different from the others and from what is already in the cast and sets.',
            'drawable' => 'Every description is concrete enough to draw it the same way every time.',
            'photos' => 'Things visible in the uploaded photos are used where they fit and point to the right photo.',
            'rules' => 'Names are two to four words, no names of real people, English.',
        ];
    }
}
