<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Cuts a part out of a video with ffmpeg, frame-accurately, by encoding the
 * part again. The sound, when there is any, is cut the same way.
 */
class VideoTrimmer
{
    /**
     * @throws RuntimeException when ffmpeg fails
     */
    public function trim(string $input, string $output, float $start, float $end): void
    {
        $result = Process::timeout(300)->run([
            Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error',
            '-ss', $this->number($start), '-i', $input, '-t', $this->number($end - $start),
            '-map', '0:v:0', '-map', '0:a?',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $output,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg could not trim the video: ' . mb_substr(trim($result->errorOutput()), -500));
        }
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
