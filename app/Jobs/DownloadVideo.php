<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Support\Video\ActEnd;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\SilentVideo;
use App\Support\Video\VideoTrimmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a finished Luma clip onto its shot, without sound and cut where
 * the act ends, logs the generation with its cost and tells the director.
 */
#[DeleteWhenMissingModels]
class DownloadVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(LumaVideoClient $videos): void
    {
        $shot = $this->shot->load('project');

        // A newer render was started in the meantime.
        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        [$clip, $seconds] = $this->cut($shot, $videos->download($this->jobId));

        $shot->addMediaFromString(app(SilentVideo::class)->strip($clip))
            ->usingFileName("shot-{$shot->position}.mp4")
            ->withCustomProperties([Shot::VIDEO_SECONDS => $seconds])
            ->toMediaCollection(Shot::VIDEO);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => GenerateVideo::PROVIDER,
            'model' => GenerateVideo::model(),
            'prompt' => $shot->video_prompt,
            'cost' => LumaVideoClient::cost(LumaVideoClient::lengthFor(GenerateVideo::duration($shot)), $shot->videoResolution()),
            'duration_ms' => self::waitedMs($shot),
        ]);

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        self::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * Log the failed render and give the shot back to the director to try again.
     */
    public static function markFailed(Shot $shot, string $error): void
    {
        $shot->loadMissing('project');

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => GenerateVideo::PROVIDER,
            'model' => GenerateVideo::model(),
            'prompt' => $shot->video_prompt,
            'duration_ms' => self::waitedMs($shot),
            'error' => $error,
        ]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The video could not be rendered. Please try again.'),
        ])->save();
    }

    /**
     * The clip cut to the shot, and its length: with an end frame, where
     * the act ends, as Ray timed it; with one frame, or when the act end
     * cannot be read, at the shot's planned length.
     *
     * @return array{string, float|int}
     */
    private function cut(Shot $shot, string $clip): array
    {
        $directory = storage_path('tmp/cut-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/clip.mp4", $clip);
            $seconds = ($shot->keyframes()->count() > 1 ? app(ActEnd::class)->seconds("{$directory}/clip.mp4") : null) ?? GenerateVideo::duration($shot);

            if ($seconds >= LumaVideoClient::lengthFor(GenerateVideo::duration($shot))) {
                return [$clip, $seconds];
            }

            app(VideoTrimmer::class)->trim("{$directory}/clip.mp4", "{$directory}/cut.mp4", 0.0, (float) $seconds);

            return [File::get("{$directory}/cut.mp4"), $seconds];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private static function waitedMs(Shot $shot): ?int
    {
        return $shot->video_submitted_at === null ? null : (int) $shot->video_submitted_at->diffInMilliseconds(now());
    }
}
