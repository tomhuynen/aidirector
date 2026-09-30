<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\StorylineWriter;
use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Plans the keyframes for a shot from its chosen storyline.
 */
#[DeleteWhenMissingModels]
class GenerateStoryline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        public readonly ?string $instruction = null,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');
        $writer = new StorylineWriter($shot);
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt(
            $writer->promptFor($this->instruction),
            provider: 'openrouter',
            model: Config::get('pipeline.models.text'),
        );

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? Config::get('pipeline.models.text'),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $shot->forceFill([
            'storyline' => ['keyframes' => array_values($response['keyframes'])],
            'storyline_error' => null,
            'status' => ShotStatus::STORYLINE_READY,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'text',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.text'),
            'error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'storyline_error' => __('The keyframes could not be planned. Please try again.'),
            'status' => $this->shot->storyline === null ? ShotStatus::OPTIONS_READY : ShotStatus::STORYLINE_READY,
        ])->save();
    }
}
