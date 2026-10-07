<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\StorylineWriter;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Plans the keyframes for a shot from its chosen storyline, then hands the
 * plan to the job that renders them.
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
        /** Off for shots created in bulk: they are planned, and drawn once the director asks for it. */
        public readonly bool $draw = true,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');

        // A fresh plan lets the planner choose the kind again; a revision keeps the current one.
        if (blank($this->instruction) || $shot->storyline === null) {
            $shot->kind = null;
        }

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

        $shot->forgetKeyframes();

        $title = trim((string) ($response['title'] ?? ''));

        $shot->forceFill([
            // A shot still named after its takeaway gets the planner's title; a title someone gave it stays.
            'title' => $title !== '' && (blank($shot->title) || $shot->title === Str::limit((string) $shot->takeaway, 80)) ? $title : $shot->title,
            'chosen_storyline' => [
                'title' => $title !== '' ? $title : (string) $shot->title,
                'storyline' => trim((string) ($response['storyline'] ?? '')),
            ],
            'kind' => $shot->kind ?? ShotKind::tryFrom((string) ($response['kind'] ?? '')) ?? ShotKind::SCENE,
            'storyline' => [
                'mode' => 'auto',
                'framing' => $response['framing'],
                'keyframes' => array_values($response['keyframes']),
            ],
            'storyline_error' => null,
            'voice_over' => null,
            'status' => $this->draw ? ShotStatus::FIRST_KEYFRAME_PENDING : ShotStatus::STORYLINE_READY,
        ])->save();

        if ($this->draw) {
            GenerateKeyframes::dispatch($shot);
        }

        GenerateVoiceOver::dispatch($shot);
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
            'status' => $this->shot->storyline === null ? ShotStatus::DRAFT : ShotStatus::STORYLINE_READY,
        ])->save();
    }
}
