<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Video;

use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\VideoTrimRequest;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Video\VideoTrimmer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TrimController
{
    use GuardsBusyShots;

    /** The shortest a trimmed video may be, in seconds. */
    public const float MIN_SECONDS = 0.5;

    /**
     * Cut the shot's video to the part between start and end of the video as
     * it was rendered, which is kept so the cut can be widened again. The
     * whole video takes the rendered one back.
     */
    public function store(VideoTrimRequest $request, Project $project, Shot $shot, VideoTrimmer $trimmer)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->canTrimVideo()) {
            throw ValidationException::withMessages(['trim' => __('This video cannot be trimmed.')]);
        }

        $this->ensureShotIdle($shot, 'trim');

        $original = $shot->videoOriginal() ?? $shot->video()->copy($shot, Shot::VIDEO_ORIGINAL);
        $seconds = (float) $original->getCustomProperty(Shot::VIDEO_SECONDS);
        $start = $request->start();
        $end = min($request->end(), $seconds);

        if ($end - $start < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['trim' => __('Keep at least :seconds s of the video.', ['seconds' => (string) self::MIN_SECONDS])]);
        }

        if ($start <= 0.0 && $end >= $seconds) {
            $original->copy($shot, Shot::VIDEO, $original->disk, $original->file_name);
            $shot->clearMediaCollection(Shot::VIDEO_ORIGINAL);

            return redirect()->route('public.shots.view', [$project, $shot]);
        }

        $this->cut($shot, $original, $trimmer, $start, $end);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    private function cut(Shot $shot, Media $original, VideoTrimmer $trimmer, float $start, float $end): void
    {
        $directory = storage_path('tmp/trim-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $input = "{$directory}/original." . (pathinfo($original->file_name, PATHINFO_EXTENSION) ?: 'mp4');
            $stream = Storage::disk($original->disk)->readStream($original->getPathRelativeToRoot());
            File::put($input, $stream === null ? '' : (string) stream_get_contents($stream));

            $output = "{$directory}/trimmed.mp4";
            $trimmer->trim($input, $output, $start, $end);

            $shot->addMedia($output)
                ->usingFileName("shot-{$shot->position}.mp4")
                ->withCustomProperties([
                    Shot::VIDEO_SECONDS => round($end - $start, 3),
                    Shot::VIDEO_TRIM => ['start' => $start, 'end' => $end, 'original' => $original->getKey()],
                ])
                ->toMediaCollection(Shot::VIDEO);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
