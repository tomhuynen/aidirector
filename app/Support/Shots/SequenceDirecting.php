<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Ai\Agents\SceneDirector;
use App\Jobs\GenerateStoryline;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Has the scene director direct a story into a sequence of shots, to show
 * in the chat for the director to accept.
 */
final class SequenceDirecting
{
    /**
     * Returns the direction, or why there is none: the scene director
     * failed, or its sequence misses a fact or shows what must not happen;
     * and what the story needs that the cast and sets do not have.
     *
     * @param  list<string>  $leftOut  names of the cast and sets the director left out
     * @return array{0: SceneDirection|null, 1: string|null, 2: list<array{name: string, type: string, description: string}>}
     */
    public static function direct(Project $project, Shot $shot, string $story, array $leftOut = []): array
    {
        $story = trim($story);

        if ($story === '') {
            return [null, null, []];
        }

        $director = new SceneDirector($shot, $story, $leftOut);
        $model = (string) Config::get('pipeline.models.scene_director');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $director->prompt($director->promptFor(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return [null, __('(I could not lay out the shots right now. Ask me again.)'), []];
        }

        $shot->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $director->promptFor(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $answer = $response->toArray();
        $direction = SceneDirection::fromArray($answer, $project)->without($leftOut);
        $problems = $direction->problems();

        if ($problems !== []) {
            return [null, __('(The sequence does not hold up yet: :problems Tell me the story again, or what to change.)', ['problems' => implode(' ', $problems)]), []];
        }

        return [$direction, null, GenerateStoryline::newElementsFrom($shot, $answer['new_elements'] ?? null)];
    }
}
