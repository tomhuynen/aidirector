<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\VideoPromptWriter;
use App\Ai\Prompts\VideoPrompt;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Video\CollageBuilder;
use App\Support\Video\OpenRouterVideoClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Turns the shot's keyframes into a video request: composes the numbered
 * collage, has the text model write the shot-specific prompt, submits both
 * to the video model and hands off to {@see PollVideo}.
 */
#[DeleteWhenMissingModels]
class GenerateVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(CollageBuilder $collages, OpenRouterVideoClient $videos): void
    {
        $shot = $this->shot->load(['project', 'keyframes.media']);
        $keyframes = $shot->keyframes;

        if ($keyframes->isEmpty() || $keyframes->contains(fn(Keyframe $keyframe) => $keyframe->render() === null)) {
            throw new RuntimeException('Every keyframe needs an image before the video can be rendered.');
        }

        $duration = self::duration($shot);
        $collage = $collages->build($keyframes->map(fn(Keyframe $keyframe) => $this->bytes($keyframe))->all());

        $shot->addMediaFromString($collage)
            ->usingFileName("collage-{$shot->position}.jpg")
            ->toMediaCollection(Shot::COLLAGE);

        $prompt = VideoPrompt::compose($this->writePrompt($shot, $keyframes, $duration), self::timeline($keyframes, $duration), $duration);

        $jobId = $videos->submit(
            Config::get('pipeline.models.video'),
            $prompt,
            ['data:image/jpeg;base64,' . base64_encode($collage)],
            $duration,
            $shot->aspectRatio()->value,
        );

        $shot->forceFill([
            'video_prompt' => $prompt,
            'video_job_id' => $jobId,
            'video_error' => null,
        ])->save();

        PollVideo::dispatch($shot, $jobId, now()->getTimestamp())
            ->delay(now()->addSeconds((int) Config::get('pipeline.video.poll_seconds')));
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.video'),
            'prompt' => $this->shot->video_prompt,
            'error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be started. Please try again.'),
        ])->save();
    }

    /**
     * The clip length: the shot's duration within what the video model accepts.
     */
    public static function duration(Shot $shot): int
    {
        return max(
            (int) Config::get('pipeline.video.min_duration'),
            min((int) Config::get('pipeline.video.max_duration'), $shot->durationInSeconds()),
        );
    }

    /**
     * When each keyframe is reached, spread evenly over the clip like the editor shows them.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{position: int, title: string, seconds: float}>
     */
    public static function timeline(Collection $keyframes, int $duration): array
    {
        $steps = max($keyframes->count() - 1, 1);

        return $keyframes->values()
            ->map(fn(Keyframe $keyframe, int $index) => [
                'position' => $keyframe->position,
                'title' => $keyframe->title,
                'seconds' => round($duration * $index / $steps, 1),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{style: string, action: string, details: string}
     */
    private function writePrompt(Shot $shot, Collection $keyframes, int $duration): array
    {
        $writer = new VideoPromptWriter($shot);
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt($writer->promptFor($keyframes, $duration), provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        return [
            'style' => (string) $response['style'],
            'action' => (string) $response['action'],
            'details' => (string) $response['details'],
        ];
    }

    private function bytes(Keyframe $keyframe): string
    {
        $render = $keyframe->render();

        return (string) Storage::disk($render->disk)->get($render->getPathRelativeToRoot());
    }
}
