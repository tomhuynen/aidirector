<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes a clip from one still with ffmpeg: the still fills the frame and the
 * camera pushes in slowly toward its middle, without sound.
 */
class StillVideo
{
    private const int FPS = 25;

    /**
     * @throws RuntimeException when ffmpeg fails
     */
    public function make(string $still, float $seconds, string $aspectRatio, string $resolution): string
    {
        ['width' => $width, 'height' => $height] = VideoFormats::size($aspectRatio, $resolution);
        $frames = max((int) round($seconds * self::FPS), 1);
        $zoom = (float) Config::get('pipeline.insert.push_in');
        $name = Str::uuid()->toString();
        $input = sys_get_temp_dir() . "/still-{$name}";
        $output = sys_get_temp_dir() . "/insert-{$name}.mp4";

        // Scaled up before the zoom, so the push-in moves smoothly instead of in whole pixels.
        $filter = sprintf(
            'scale=%1$d:%2$d:force_original_aspect_ratio=increase,crop=%1$d:%2$d,scale=%3$d:%4$d,zoompan=z=\'1+%5$F*on/%6$d\':x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':d=1:s=%1$dx%2$d:fps=%7$d',
            $width,
            $height,
            $width * 4,
            $height * 4,
            $zoom,
            max($frames - 1, 1),
            self::FPS,
        );

        try {
            file_put_contents($input, $still);

            $result = Process::timeout(300)->run([
                (string) Config::get('pipeline.ffmpeg.binary'), '-y', '-loglevel', 'error',
                '-loop', '1', '-framerate', (string) self::FPS, '-i', $input,
                '-vf', $filter, '-frames:v', (string) $frames,
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', $output,
            ]);

            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('ffmpeg could not make the clip from the still: ' . mb_substr(trim($result->errorOutput()), -500));
            }

            return (string) file_get_contents($output);
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
