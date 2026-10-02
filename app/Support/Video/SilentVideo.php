<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Removes the audio track from a clip. Some video models add music even
 * when asked for none; the picture is copied as is, so nothing is re-encoded.
 */
class SilentVideo
{
    /**
     * The clip without audio, or the clip unchanged when ffmpeg is missing or fails.
     */
    public function strip(string $video): string
    {
        $ffmpeg = (string) Config::get('pipeline.video.ffmpeg');
        $name = Str::uuid()->toString();
        $input = sys_get_temp_dir() . "/clip-{$name}.mp4";
        $output = sys_get_temp_dir() . "/silent-{$name}.mp4";

        try {
            file_put_contents($input, $video);

            $result = Process::timeout(120)->run([$ffmpeg, '-y', '-loglevel', 'error', '-i', $input, '-c:v', 'copy', '-an', $output]);

            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                Log::warning('Could not remove the audio from a video.', ['error' => $result->errorOutput()]);

                return $video;
            }

            return (string) file_get_contents($output);
        } catch (Throwable $exception) {
            Log::warning('Could not remove the audio from a video.', ['error' => $exception->getMessage()]);

            return $video;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
