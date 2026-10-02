<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\StorylineOptionsWriter;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\Shot;
use Illuminate\Support\Collection;

/**
 * Suggesting storylines for every shot with a brief.
 */
class StorylineOptionsBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'storyline-options';
    }

    public function description(): string
    {
        return 'Suggest different storylines for a shot brief.';
    }

    public function cases(): Collection
    {
        return Shot::query()->with('project')->whereNotNull('takeaway')->orderBy('id')->get()->map(fn(Shot $shot) => new BenchCase(
            key: "shot-{$shot->getKey()}",
            label: "{$shot->project->title} · {$shot->title}",
            data: ['shot' => $shot->getKey()],
        ));
    }

    /**
     * Without the shot's current suggestions, so every variant writes a
     * fresh set as for a new brief.
     */
    public function agent(BenchCase $case): StorylineOptionsWriter
    {
        $shot = Shot::query()->with('project')->findOrFail($case->data['shot']);
        $shot->storyline_options = null;

        return new StorylineOptionsWriter($shot);
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptFor();
    }

    public function expectedOutputTokens(): int
    {
        return 450;
    }

    public function criteria(): array
    {
        return [
            'distinct' => 'The storylines are genuinely different scenes (place, moment, cue, people, resolution), not takes of the same scene.',
            'takeaway' => 'Every storyline lands the takeaway clearly for the viewer.',
            'filmable' => 'Each storyline can be shown in the shot length with a few clearly readable keyframes.',
            'rules' => 'Titles are two to four words and not the name of an angle, two to four sentences in present tense, no camera language, no text in frame, no sound, English.',
            'cast' => 'The cast and sets are reused where they fit, by exact name, without forcing them in.',
        ];
    }
}
