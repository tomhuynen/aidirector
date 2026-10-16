<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\SilentVideo;
use App\Support\Video\VideoJobs;
use App\Support\Video\VideoTrimmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a finished clip onto its shot, without sound, logs the
 * generation with its usage and tells the director. A part of a merged
 * shot joins the merged video again.
 */
#[DeleteWhenMissingModels]
class DownloadVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    /**
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly Shot $shot,
        public readonly string $jobId,
        public readonly array $usage = [],
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(VideoJobs $videos): void
    {
        $shot = $this->shot->load('project');

        // A newer render was started in the meantime.
        if ($shot->video_job_id !== $this->jobId) {
            return;
        }

        $clip = $videos->download($this->jobId);

        // Luma renders 5 or 10 seconds; the last keyframe is held past the shot's end, which is cut off.
        if (GenerateVideo::rendersOnLuma() && LumaVideoClient::lengthFor(GenerateVideo::duration($shot)) > GenerateVideo::duration($shot)) {
            $clip = $this->cut($clip, GenerateVideo::duration($shot));
        }

        $shot->addMediaFromString(app(SilentVideo::class)->strip($clip))
            ->usingFileName("shot-{$shot->position}.mp4")
            ->withCustomProperties([Shot::VIDEO_SECONDS => GenerateVideo::duration($shot)])
            ->toMediaCollection(Shot::VIDEO);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'video',
            'provider' => GenerateVideo::provider(),
            'model' => GenerateVideo::model(),
            'prompt' => $shot->video_prompt,
            'cost' => GenerateVideo::rendersOnLuma()
                ? LumaVideoClient::cost(LumaVideoClient::lengthFor(GenerateVideo::duration($shot)), $shot->videoResolution())
                : (is_numeric($this->usage['cost'] ?? null) ? $this->usage['cost'] : null),
            'duration_ms' => self::waitedMs($shot),
            'usage' => $this->usage ?: null,
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
            'provider' => GenerateVideo::provider(),
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

    private function cut(string $clip, int $seconds): string
    {
        $directory = storage_path('tmp/cut-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/clip.mp4", $clip);
            app(VideoTrimmer::class)->trim("{$directory}/clip.mp4", "{$directory}/cut.mp4", 0.0, (float) $seconds);

            return File::get("{$directory}/cut.mp4");
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private static function waitedMs(Shot $shot): ?int
    {
        return $shot->video_submitted_at === null ? null : (int) $shot->video_submitted_at->diffInMilliseconds(now());
    }
}
