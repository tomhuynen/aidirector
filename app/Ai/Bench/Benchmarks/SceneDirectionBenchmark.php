<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\SceneDirector;
use App\Ai\Bench\AgentBenchmark;
use App\Ai\Bench\BenchCase;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Directing a story into shots, on the idea of every shot that has one: the
 * decisive change found, the fewest shots that show it, the intention made
 * visible, and the frames continuous and drawable.
 */
class SceneDirectionBenchmark extends AgentBenchmark
{
    public function name(): string
    {
        return 'scene-direction';
    }

    public function description(): string
    {
        return 'Direct a story into shots: the decisive moment, the fewest shots, intent made visible.';
    }

    public function cases(): Collection
    {
        return Shot::query()->with('project')->whereNotNull('idea')->where('idea', '!=', '')->orderBy('id')->get()
            ->map(fn(Shot $shot) => new BenchCase(
                key: "shot-{$shot->getKey()}",
                label: "{$shot->project->title} · {$shot->title}",
                data: ['shot' => $shot->getKey()],
            ))
            ->values();
    }

    public function agent(BenchCase $case): SceneDirector
    {
        $shot = Shot::query()->with('project')->findOrFail($case->data['shot']);

        return new SceneDirector($shot, (string) $shot->idea);
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptFor();
    }

    public function model(): string
    {
        return (string) Config::get('pipeline.models.scene_director');
    }

    public function expectedOutputTokens(): int
    {
        return 1500;
    }

    public function criteria(): array
    {
        return [
            'decisive_moment' => 'The direction names the decisive change of the story and gives that moment the strongest emphasis: the closest shot or a held pause, with the facts a viewer must see all shown.',
            'fewest_shots' => 'Every shot and every cut has a purpose a viewer gains from; no shot repeats what the one before showed, and no wide-medium-close-wide pattern by habit.',
            'visible_intent' => 'Intentions and thoughts are turned into observable behaviour (gaze, posture, a pause, a gesture) that a viewer reads without words; what cannot be shown is flagged as context, not explained with an invented sign or prop.',
            'continuity' => 'A shot that continues starts exactly where the one before ended; positions, hands, objects and screen direction agree from frame to frame, and nothing appears or disappears between a start and an end frame.',
            'drawable' => 'Each start and end frame is one readable state in the staging fields, within what the image and video models can do: one act per shot, a static camera, 2 to 10 seconds, and the people, places and objects of the cast and sets only.',
        ];
    }
}
