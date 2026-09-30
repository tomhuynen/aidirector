<?php

declare(strict_types=1);

namespace App\Support\Style;

use App\Ai\Agents\StyleOptionsWriter;
use App\Ai\Prompts\StyleSheetPrompt;
use App\Enums\StyleOptionStatus;
use App\Jobs\GenerateStyleOption;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Starts one round of the style exploration: asks the writer for the
 * styles, records a pending option per style and queues its render.
 */
class StartStyleRound
{
    /**
     * @return Collection<int, StyleOption>
     */
    public function start(Project $project, ?StyleOption $parent = null): Collection
    {
        $writer = new StyleOptionsWriter($project, $parent);
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt($writer->promptText(), provider: 'openrouter', model: $model);

        $project->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $subjects = $project->styleSheetSubjects();
        $round = $parent === null ? 1 : $parent->round + 1;

        $options = collect($response->toArray()['styles'] ?? [])
            ->values()
            ->map(function (array $style, int $index) use ($project, $parent, $round, $subjects): StyleOption {
                $style = [
                    'name' => (string) $style['name'],
                    'look' => (string) $style['look'],
                    'medium' => (string) $style['medium'],
                    'mood' => (string) $style['mood'],
                    'palette' => (string) $style['palette'],
                    'lighting' => (string) $style['lighting'],
                ];

                return $project->styleOptions()->create([
                    'parent_id' => $parent?->id,
                    'round' => $round,
                    'position' => $index + 1,
                    'style' => $style,
                    'prompt' => StyleSheetPrompt::for($style, $subjects),
                    'status' => StyleOptionStatus::PENDING,
                ]);
            });

        $options->each(fn(StyleOption $option) => GenerateStyleOption::dispatch($option));

        return $options;
    }
}
