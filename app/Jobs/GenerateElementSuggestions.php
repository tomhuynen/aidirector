<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\ElementSuggester;
use App\Enums\ElementRoundStatus;
use App\Enums\ElementSuggestionStatus;
use App\Models\ElementRound;
use App\Support\Elements\PhotoInventory;
use App\Support\Elements\StartElementRound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Writes a cast and sets round's suggestions and queues a render for each,
 * so the chat's grid fills in as the images arrive.
 */
#[DeleteWhenMissingModels]
class GenerateElementSuggestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly ElementRound $round,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(StartElementRound $rounds): void
    {
        $round = $this->round->load('project');
        $project = $round->project;
        $writer = new ElementSuggester($round);
        $model = (string) Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt($writer->promptText(), provider: 'openrouter', model: $model);

        $round->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $photos = PhotoInventory::photos($project);

        collect($response->toArray()['suggestions'] ?? [])
            ->filter(fn(mixed $item) => is_array($item) && filled($item['name'] ?? null))
            ->take($writer->count())
            ->values()
            ->each(function (array $item, int $index) use ($round, $photos) {
                $photo = is_int($item['photo'] ?? null) ? $photos->get($item['photo'] - 1) : null;

                $suggestion = $round->suggestions()->create([
                    'position' => $index + 1,
                    'name' => mb_substr((string) $item['name'], 0, 255),
                    'description' => (string) ($item['description'] ?? ''),
                    'source_media_id' => $photo?->id,
                    'status' => ElementSuggestionStatus::PENDING,
                ]);

                RenderElementSuggestion::dispatch($suggestion);
            });

        $round->forceFill(['status' => ElementRoundStatus::READY, 'error' => null])->save();

        // Only now that these renders are queued, the next prepared category may write its own.
        $rounds->dispatchNextWaiting($project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->round->forceFill([
            'status' => ElementRoundStatus::FAILED,
            'error' => __('The suggestions could not be written. Please ask again.'),
        ])->save();

        $project = $this->round->project()->first();

        if ($project !== null) {
            app(StartElementRound::class)->dispatchNextWaiting($project);
        }
    }
}
