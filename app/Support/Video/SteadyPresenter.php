<?php

declare(strict_types=1);

namespace App\Support\Video;

use App\Ai\KeyframePainter;
use App\Models\Shot;
use App\Support\Images\ReplicateClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Puts the moving presenter on a background that never moves: the video
 * model animates the whole picture and sometimes invents people walking by
 * behind the presenter. The presenter is cut out of every frame by a video
 * matting model and laid over their keyframe without them, so only they
 * move and the sound stays as it was.
 */
class SteadyPresenter
{
    /** How long the matting model may take, in seconds: it works through every frame. */
    private const int MATTING_SECONDS = 420;

    public function __construct(
        private readonly KeyframePainter $keyframes,
        private readonly ReplicateClient $replicate,
    ) {}

    public function enabled(): bool
    {
        return (bool) Config::get('pipeline.presenter.steady_background.enabled') && ReplicateClient::configured();
    }

    /**
     * The presenter's video on their still background, or the video as it
     * came when that cannot be made, so the shot still gets its video.
     */
    public function steady(Shot $shot, string $video): string
    {
        $keyframe = $shot->keyframes()->orderBy('position')->first();
        $render = $keyframe?->render();

        if (! $this->enabled() || $render === null) {
            return $video;
        }

        try {
            $background = $this->keyframes->presenterBackground($keyframe, $render);

            return $this->overlay($video, $this->matte($video), (string) Storage::disk($background->disk)->get($background->getPathRelativeToRoot()));
        } catch (Throwable $exception) {
            report($exception);

            return $video;
        }
    }

    /**
     * The presenter in every frame as a grey video: white is the presenter.
     */
    private function matte(string $video): string
    {
        $file = $this->replicate->upload($video, 'presenter.mp4', 'video/mp4');

        try {
            $output = $this->replicate->run((string) Config::get('pipeline.presenter.steady_background.matting_model'), [
                'input_video' => $file['url'],
                'output_type' => 'alpha-mask',
            ], self::MATTING_SECONDS);
        } finally {
            $this->replicate->forget($file['id']);
        }

        $url = is_array($output) ? end($output) : $output;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The matting model returned no video.');
        }

        return $this->replicate->download($url);
    }

    /**
     * The presenter, cut out by the matte, on the background at the size of the video, with the video's sound.
     */
    private function overlay(string $video, string $matte, string $background): string
    {
        $directory = storage_path('tmp/presenter-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put("{$directory}/video.mp4", $video);
            File::put("{$directory}/matte.mp4", $matte);
            File::put("{$directory}/background.png", $background);

            $result = Process::timeout(600)->run([
                Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error',
                '-i', "{$directory}/video.mp4",
                '-i', "{$directory}/matte.mp4",
                '-loop', '1', '-i', "{$directory}/background.png",
                '-filter_complex', '[1:v][0:v]scale2ref[matte][presenter];[matte]format=gray[alpha];[presenter][alpha]alphamerge[cut];'
                    . '[2:v][cut]scale2ref[still][cutout];[still]setsar=1[bg];[bg][cutout]overlay=shortest=1,format=yuv420p[v]',
                '-map', '[v]', '-map', '0:a?',
                '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', '-c:a', 'copy',
                '-shortest', '-movflags', '+faststart',
                "{$directory}/steady.mp4",
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('ffmpeg could not put the presenter on the background: ' . mb_substr(trim($result->errorOutput()), -500));
            }

            return File::get("{$directory}/steady.mp4");
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
