<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Video\ClipJoiner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Joins the videos of a merged shot's parts into the merged shot's video,
 * with the transition the director picked, then the parts' audio tracks.
 */
#[DeleteWhenMissingModels]
class MergeShotVideos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ClipJoiner $joiner): void
    {
        $shot = $this->shot->load(['project', 'parts.media']);
        $videos = $shot->parts->map(fn(Shot $part) => $part->video());

        if ($shot->merge_transition === null || $videos->count() < 2 || $videos->contains(null)) {
            throw new RuntimeException('Every part of the merged shot needs a video.');
        }

        $directory = storage_path('tmp/merge-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $paths = $videos->values()->map(function (Media $video, int $index) use ($directory): string {
                $path = "{$directory}/part-{$index}." . pathinfo($video->file_name, PATHINFO_EXTENSION);
                $stream = Storage::disk($video->disk)->readStream($video->getPathRelativeToRoot());
                File::put($path, $stream === null ? '' : (string) stream_get_contents($stream));

                return $path;
            })->all();

            $output = "{$directory}/merged.mp4";
            $timeline = $joiner->join($paths, $shot->merge_transition, $output);

            $shot->addMedia($output)
                ->usingFileName("shot-{$shot->position}.mp4")
                ->withCustomProperties([Shot::PART_STARTS => $timeline['starts'], Shot::VIDEO_SECONDS => $timeline['duration']])
                ->toMediaCollection(Shot::VIDEO);
        } finally {
            File::deleteDirectory($directory);
        }

        $shot->forceFill([
            'status' => ShotStatus::VIDEO_READY,
            'video_error' => null,
            'voice_over' => $shot->parts->pluck('voice_over')->filter()->join(' ') ?: null,
        ])->save();

        MergeShotAudio::start($shot);

        GenerationFinished::ready(__('The merged video of “:shot” is ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
            ->sendTo($shot->project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_READY,
            'video_error' => __('The clips could not be joined. Please try again.'),
        ])->save();

        report($exception);

        GenerationFinished::failed(__('The clips of “:shot” could not be joined', ['shot' => $this->shot->title]), route('public.shots.view', [$this->shot->project, $this->shot]))
            ->sendTo($this->shot->project);
    }
}
