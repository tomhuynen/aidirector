<?php

declare(strict_types=1);

namespace App\Support\Audio;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Measures and tightens spoken audio with ffmpeg, so a track fits its clip.
 */
class AudioLength
{
    public function seconds(string $audio): float
    {
        return $this->withFile($audio, function (string $path): float {
            $result = Process::timeout(60)->run([(string) Config::get('pipeline.ffmpeg.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);

            if ($result->failed() || ! is_numeric(trim($result->output()))) {
                throw new RuntimeException('Could not measure the audio: ' . trim($result->errorOutput()));
            }

            return (float) trim($result->output());
        });
    }

    /**
     * The audio played faster by the given factor, with the pitch kept.
     */
    public function speedUp(string $audio, float $factor): string
    {
        return $this->withFile($audio, function (string $path) use ($factor): string {
            $output = sys_get_temp_dir() . '/tempo-' . Str::uuid()->toString() . '.mp3';

            try {
                Process::timeout(60)->run([(string) Config::get('pipeline.ffmpeg.binary'), '-y', '-loglevel', 'error', '-i', $path, '-filter:a', sprintf('atempo=%.3F', $factor), $output])->throw();

                return (string) file_get_contents($output);
            } finally {
                @unlink($output);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function withFile(string $audio, callable $callback): mixed
    {
        $path = sys_get_temp_dir() . '/audio-' . Str::uuid()->toString() . '.mp3';
        file_put_contents($path, $audio);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
