<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Ai\Agents\SceneDirector;
use App\Ai\Agents\SequenceReviewer;
use App\Enums\ElementType;
use App\Jobs\GenerateStoryline;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Has the scene director direct a story into a sequence of shots, has the
 * sequence reviewer check it and the scene director repair it once, to show
 * in the chat for the director to accept.
 */
final class SequenceDirecting
{
    /**
     * Returns the direction, with what the review still found after the
     * repair, or why there is none: the scene director failed, or its
     * sequence misses a fact or shows what must not happen; and what the
     * story needs that the cast and sets do not have.
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

        $answer = self::answer($project, $shot, new SceneDirector($shot, $story, $leftOut));

        if ($answer === null) {
            return [null, __('(I could not lay out the shots right now. Ask me again.)'), []];
        }

        $places = $project->elements()->where('type', ElementType::PLACE)->pluck('name')->all();
        $direction = SceneDirection::fromArray($answer, $project)->without($leftOut);
        $problems = [...$direction->problems(), ...$direction->unplaced($places)];
        $issues = $problems === [] ? self::review($project, $shot, $story, $direction) : [];

        // One repair for what the checks or the review found.
        if ($problems !== [] || $issues !== []) {
            $repaired = self::answer($project, $shot, new SceneDirector($shot, $story, $leftOut, [...$problems, ...$issues], $answer));

            if ($repaired !== null) {
                $answer = $repaired;
                $direction = SceneDirection::fromArray($answer, $project)->without($leftOut);
                $problems = [...$direction->problems(), ...$direction->unplaced($places)];
                $issues = $problems === [] ? self::review($project, $shot, $story, $direction) : [];
            }
        }

        if ($problems !== []) {
            return [null, __('(The sequence does not hold up yet: :problems Tell me the story again, or what to change.)', ['problems' => implode(' ', $problems)]), []];
        }

        return [$direction->withIssues($issues), null, GenerateStoryline::newElementsFrom($shot, $answer['new_elements'] ?? null)];
    }

    /**
     * The scene director's answer, logged as a generation of the shot, or
     * null when it failed.
     *
     * @return array<string, mixed>|null
     */
    private static function answer(Project $project, Shot $shot, SceneDirector $director): ?array
    {
        $model = (string) Config::get('pipeline.models.scene_director');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $director->prompt($director->promptFor(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        self::log($project, $shot, $response, $model, $director->promptFor(), $started);

        return $response->toArray();
    }

    /**
     * What the sequence reviewer found in the direction; nothing when the
     * review failed, so it never blocks the sequence.
     *
     * @return list<string>
     */
    private static function review(Project $project, Shot $shot, string $story, SceneDirection $direction): array
    {
        $reviewer = new SequenceReviewer($shot, $story, $direction);
        $model = (string) Config::get('pipeline.models.sequence_reviewer');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $reviewer->prompt($reviewer->promptFor(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        self::log($project, $shot, $response, $model, $reviewer->promptFor(), $started);

        return array_values(array_filter(array_map(fn(mixed $issue) => trim((string) $issue), (array) ($response['issues'] ?? [])), fn(string $issue) => $issue !== ''));
    }

    private static function log(Project $project, Shot $shot, StructuredAgentResponse $response, string $model, string $prompt, int $started): void
    {
        $shot->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);
    }
}
