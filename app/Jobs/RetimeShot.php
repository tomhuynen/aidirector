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

    public bool $failOnTimeout = true;

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

        $answer = $response->toArray();
        $seconds = Shot::clampSeconds((int) ($answer['seconds'] ?? $current));
        $timed = array_values(array_filter((array) ($answer['keyframes'] ?? []), 'is_numeric'));

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [
            ...($storyline ?? []),
            'framing' => [...(array) ($storyline['framing'] ?? []), 'seconds' => $seconds],
            // Each keyframe keeps its own time, when the timer gave one for every keyframe.
            'keyframes' => count($timed) === count((array) ($storyline['keyframes'] ?? []))
                ? array_map(fn(array $keyframe, mixed $time) => [...$keyframe, 'seconds' => max(0.0, (float) $time)], array_values((array) $storyline['keyframes']), $timed)
                : (array) ($storyline['keyframes'] ?? []),
        ]);

        if ($seconds === $current) {
            return;
        }

        $shot->forceFill(['voice_over' => null])->save();

        GenerateVoiceOver::automatically($shot);
    }
}
