<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\PlaceLayoutReader;
use App\Models\Element;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Reads the layout of a place from one version of its picture and keeps it
 * on that version, so choosing another version brings its own layout. A
 * place whose layout is not read yet is directed from its description.
 */
#[DeleteWhenMissingModels]
class ReadPlaceLayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Media $picture,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $place = $this->picture->model;

        if (! $place instanceof Element) {
            return;
        }

        $reader = new PlaceLayoutReader($place, $this->picture);
        $model = (string) Config::get('pipeline.models.place_layout');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $reader->prompt($reader->promptFor(), attachments: $reader->attachments(), provider: 'openrouter', model: $model);

        $place->generations()->create([
            'director_id' => $place->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $layout = trim((string) ($response->toArray()['layout'] ?? ''));

        if ($layout !== '') {
            $this->picture->setCustomProperty(Element::LAYOUT, $layout)->save();
        }
    }
}
