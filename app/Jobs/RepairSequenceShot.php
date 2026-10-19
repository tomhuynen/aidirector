<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Shot;
use App\Support\Video\ClipSplicer;
use App\Support\Video\LumaVideoClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Renders one part of a sequence clip again with Luma: from the clip's own
 * first frame of that part to its own last frame, in the time the sequence
 * model gave it, with what the director says is wrong. The end frame is
 * pinned again on the last frame so the rest holds still and is cut off.
 * {@see PollSequenceRepair} puts the new piece in place.
 */
#[DeleteWhenMissingModels]
class RepairSequenceShot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Shot $shot,
        public readonly Shot $part,
        public readonly string $instruction,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(LumaVideoClient $luma, ClipSplicer $splicer): void
    {
        $shot = $this->shot->load(['project', 'parts', 'media']);
        $video = $shot->video() ?? throw new RuntimeException('The sequence has no video to repair.');
        [$start, $end] = self::span($shot, $this->part);

        $directory = storage_path('tmp/repair-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/clip.mp4", (string) Storage::disk($video->disk)->get($video->getPathRelativeToRoot()));
            $first = $splicer->frameAt("{$directory}/clip.mp4", $start);
            $last = $splicer->frameAt("{$directory}/clip.mp4", $end - 1 / LumaVideoClient::FPS);
        } finally {
            File::deleteDirectory($directory);
        }

        $clip = LumaVideoClient::lengthFor((int) ceil($end - $start));
        $frames = min($clip * LumaVideoClient::FPS - 1, (int) round(($end - $start) * LumaVideoClient::FPS));
        $prompt = $this->prompt();

        $jobId = $luma->submit(
            GenerateVideo::model(),
            $prompt,
            [['bytes' => $first, 'mime' => 'image/png'], ['bytes' => $last, 'mime' => 'image/png'], ['bytes' => $last, 'mime' => 'image/png']],
            [0, $frames, $clip * LumaVideoClient::FPS],
            $clip,
            $shot->aspectRatio()->value,
            $shot->videoResolution() === '1080p' ? '1080p' : '720p',
        );

        $shot->forceFill(['video_job_id' => $jobId, 'video_submitted_at' => now(), 'video_error' => null])->save();

        PollSequenceRepair::dispatch($shot, $jobId, $start, $end, $prompt, $clip)->delay((int) Config::get('pipeline.video.first_poll_seconds'));
    }

    public function failed(?Throwable $exception): void
    {
        PollSequenceRepair::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * Where a part runs in the sequence clip, from its start to the next part's start or the end of the clip, in seconds.
     *
     * @return array{float, float}
     */
    public static function span(Shot $merged, Shot $part): array
    {
        $video = $merged->video();
        $starts = array_map('floatval', (array) $video?->getCustomProperty(Shot::PART_STARTS, []));
        $index = $merged->parts->search(fn(Shot $candidate) => $candidate->is($part));

        if ($index === false || ! isset($starts[$index])) {
            throw new RuntimeException('Where the part starts in the clip is not known.');
        }

        return [$starts[$index], $starts[$index + 1] ?? (float) $video->getCustomProperty(Shot::VIDEO_SECONDS)];
    }

    private function prompt(): string
    {
        $act = trim((string) ($this->part->idea ?: ($this->part->chosen_storyline['storyline'] ?? $this->part->title)));

        return implode("\n\n", array_filter([
            'One continuous shot with a fixed camera, from the supplied start frame to the supplied end frame. Keep exactly the look of the frames: the same render style, people, objects, place and light. Nothing is added and the style does not change.',
            "Action: {$act}",
            trim($this->instruction) === '' ? null : 'This must be right: ' . trim($this->instruction),
            'People move at real, natural speed. Once the end frame is reached, everything holds still exactly as in the end frame until the clip ends. No cuts, no camera movement, no text, no sound.',
        ]));
    }
}
