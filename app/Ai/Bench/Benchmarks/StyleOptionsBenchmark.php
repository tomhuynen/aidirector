<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\StyleOptionsWriter;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Support\Collection;

/**
 * Proposing a round of styles: the first round of every project, then a
 * "more like this" round from each style sheet the director could pick.
 */
class StyleOptionsBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'style-options';
    }

    public function description(): string
    {
        return 'Propose a round of styles, the first one or "more like this" from a style sheet.';
    }

    public function cases(): Collection
    {
        $firstRounds = Project::query()->orderBy('id')->get()->map(fn(Project $project) => new BenchCase(
            key: "project-{$project->getKey()}",
            label: "{$project->title} · first round",
            data: ['project' => $project->getKey()],
        ));

        $variations = StyleOption::query()->with('project')->orderBy('id')->get()->map(fn(StyleOption $option) => new BenchCase(
            key: "style-{$option->getKey()}",
            label: "{$option->project->title} · more like \"{$option->style()['name']}\"",
            data: ['project' => $option->project_id, 'parent' => $option->getKey()],
        ));

        return $firstRounds->concat($variations)->values();
    }

    public function agent(BenchCase $case): StyleOptionsWriter
    {
        $parent = isset($case->data['parent']) ? StyleOption::query()->findOrFail($case->data['parent']) : null;

        return new StyleOptionsWriter(Project::query()->findOrFail($case->data['project']), $parent);
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptText();
    }

    public function expectedOutputTokens(): int
    {
        return 450;
    }

    public function criteria(): array
    {
        return [
            'distinct' => 'Every style is visibly different from the others and easy to tell apart by its name. For a "more like this" round: every style stays in the family of the original and still differs from it.',
            'fit' => 'Every style suits the project, its purpose and its subjects.',
            'concrete' => 'Look, medium, palette and lighting are concrete enough for an image model to render the style the same way every time.',
            'rules' => 'Names are two to four words, every field is filled, no camera language, no text in the image, English.',
        ];
    }
}
