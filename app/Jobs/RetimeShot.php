<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\ShotTimer;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Times the shot again once its keyframes changed: one added, deleted or
 * described differently. A length the director set stays; a new length has
 * the voice-over written again to fit it.
 */
#[DeleteWhenMissingModels]
class RetimeShot implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function uniqueId(): string
    {
        return 'retime-shot-' . (Tenant::current()?->getKey() ?? 'landlord') . '-' . $this->shot->getKey();
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');
        $current = $shot->storylineFraming()['seconds'] ?? null;

        if ($shot->duration !== null || $current === null || $shot->storylineKeyframes() === []) {
            return;
        }

        $timer = new ShotTimer($shot);
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $timer->prompt($timer->promptFor(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $timer->promptFor(),
            'usage' => $response->usage->toArray(),
        ]);

        $seconds = Shot::clampSeconds((int) ($response->toArray()['seconds'] ?? $current));

        if ($seconds === $current) {
            return;
        }

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'framing' => [...(array) ($storyline['framing'] ?? []), 'seconds' => $seconds]]);
        $shot->forceFill(['voice_over' => null])->save();

        GenerateVoiceOver::dispatch($shot);
    }
}
