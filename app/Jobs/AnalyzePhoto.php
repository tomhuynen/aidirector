<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\PhotoAnalyst;
use App\Models\Media;
use App\Models\Project;
use App\Support\Elements\PhotoInventory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Lists what an uploaded photo shows, in the background, so the cast and
 * sets stage of the intake chat can build on it. The chat does not wait
 * for it; a photo still being analysed is reported as such.
 */
#[DeleteWhenMissingModels]
class AnalyzePhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Media $photo,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $project = $this->photo->model;

        if (! $project instanceof Project) {
            return;
        }

        $photo = $this->photo;
        $image = Image::fromStorage(
            $photo->hasGeneratedConversion(Project::REFERENCE) ? $photo->getPathRelativeToRoot(Project::REFERENCE) : $photo->getPathRelativeToRoot(),
            $photo->hasGeneratedConversion(Project::REFERENCE) ? ($photo->conversions_disk ?? $photo->disk) : $photo->disk,
        );

        $analyst = new PhotoAnalyst($project, $image);
        $model = (string) Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $analyst->prompt($analyst->promptText(), attachments: $analyst->attachments(), provider: 'openrouter', model: $model);

        $project->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $items = collect($response->toArray()['items'] ?? [])
            ->filter(fn(mixed $item) => is_array($item) && filled($item['name'] ?? null))
            ->map(fn(array $item) => [
                'type' => (string) ($item['type'] ?? 'object'),
                'name' => (string) $item['name'],
                'description' => (string) ($item['description'] ?? ''),
            ])
            ->values()
            ->all();

        $photo->setCustomProperty(PhotoInventory::PROPERTY, $items)->save();
    }

    public function failed(?Throwable $exception): void
    {
        // An empty inventory lets the chat move on instead of waiting on this photo.
        $this->photo->setCustomProperty(PhotoInventory::PROPERTY, [])->save();
    }
}
