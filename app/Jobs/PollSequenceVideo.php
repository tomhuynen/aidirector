<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Support\Images\ReplicateClient;
use App\Support\Video\SceneCuts;
use App\Support\Video\SilentVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Waits for the clip of a sequence rendered by {@see RenderSequenceVideo}:
 * while it renders the job checks again a little later; once it is done the
 * clip is stored on the merged shot, without sound, with where each part
 * starts read from the cuts in it.
 */
#[DeleteWhenMissingModels]
class PollSequenceVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const array RUNNING = ['starting', 'processing'];

    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ReplicateClient $replicate, SceneCuts $cuts, SilentVideo $silent): void
    {
        $shot = $this->shot->load(['project', 'parts']);

        // A newer render was started in the meantime.
        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        $prediction = $replicate->prediction($this->jobId);
        $waited = now()->getTimestamp() - (int) $shot->video_submitted_at?->getTimestamp();

        if (in_array($prediction['status'], self::RUNNING, true)) {
            if ($waited > (int) Config::get('pipeline.video.max_wait_minutes') * 60) {
                self::markFailed($shot, 'The video took too long to render.');

                return;
            }

            self::dispatch($shot, $this->jobId)->delay((int) Config::get('pipeline.video.poll_seconds'));

            return;
        }

        if ($prediction['status'] !== 'succeeded') {
            self::markFailed($shot, $prediction['error'] ?? "The video job ended as {$prediction['status']}.");

            return;
        }

        $url = is_array($prediction['output']) ? ($prediction['output'][0] ?? null) : $prediction['output'];
        $clip = $silent->strip($replicate->download((string) $url));
        $directory = storage_path('tmp/sequence-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/clip.mp4", $clip);
            $timeline = $cuts->read("{$directory}/clip.mp4", $shot->parts->count()) ?? ['starts' => [0.0], 'seconds' => (float) $shot->durationInSeconds()];
        } finally {
            File::deleteDirectory($directory);
        }

        $shot->addMediaFromString($clip)
            ->usingFileName("shot-{$shot->position}.mp4")
            ->withCustomProperties([Shot::PART_STARTS => $timeline['starts'], Shot::VIDEO_SECONDS => $timeline['seconds']])
            ->toMediaCollection(Shot::VIDEO);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'replicate',
            'model' => (string) Config::get('pipeline.models.sequence_video'),
            'prompt' => $shot->video_prompt,
            'duration_ms' => $waited * 1000,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => null,
            'voice_over' => $shot->parts->pluck('voice_over')->filter()->join(' ') ?: null,
        ])->save();

        MergeShotAudio::start($shot);
    }

    public function failed(?Throwable $exception): void
    {
        self::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * Logs the failed render and gives the sequence back to the director to try again.
     */
    public static function markFailed(Shot $shot, string $error): void
    {
        $shot->loadMissing('project');

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => 'replicate',
            'model' => (string) Config::get('pipeline.models.sequence_video'),
            'prompt' => $shot->video_prompt,
            'error' => $error,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be rendered. Please try again.'),
        ])->save();
    }
}
