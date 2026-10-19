<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Takes single frames out of a clip and puts a new piece into it in place
 * of the part between two moments, at the clip's own size and frame rate.
 */
class ClipSplicer
{
    /**
     * The frame at `$seconds` into the clip, as PNG bytes.
     *
     * @throws RuntimeException when ffmpeg fails
     */
    public function frameAt(string $path, float $seconds): string
    {
        $output = $path . '-' . str_replace('.', '_', (string) $seconds) . '.png';

        $result = Process::timeout(60)->run([
            Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error', '-ss', $this->number(max(0.0, $seconds)), '-i', $path, '-frames:v', '1', $output,
        ]);

        if (! $result->successful() || ! is_file($output)) {
            throw new RuntimeException('ffmpeg could not take the frame: ' . mb_substr(trim($result->errorOutput()), -300));
        }

        $bytes = (string) file_get_contents($output);
        @unlink($output);

        return $bytes;
    }

    /**
     * The clip with the part from `$start` to `$end` replaced by the start of `$piece`, as long as that part.
     *
     * @throws RuntimeException when ffmpeg fails
     */
    public function splice(string $clip, string $piece, float $start, float $end, string $output): void
    {
        [$width, $height, $fps] = $this->probe($clip);
        $scale = "scale={$width}:{$height},setsar=1,fps={$fps},format=yuv420p";

        $result = Process::timeout(300)->run([
            Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error', '-i', $clip, '-i', $piece,
            '-filter_complex', implode(';', [
                "[0:v]trim=end={$this->number($start)},setpts=PTS-STARTPTS,{$scale}[a]",
                "[1:v]trim=end={$this->number($end - $start)},setpts=PTS-STARTPTS,{$scale}[b]",
                "[0:v]trim=start={$this->number($end)},setpts=PTS-STARTPTS,{$scale}[c]",
                '[a][b][c]concat=n=3:v=1[v]',
            ]),
            '-map', '[v]', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $output,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg could not splice the clip: ' . mb_substr(trim($result->errorOutput()), -500));
        }
    }

    /**
     * @return array{int, int, string}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(60)->run([
            Config::get('pipeline.ffmpeg.ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,r_frame_rate', '-of', 'csv=p=0', $path,
        ]);

        $parts = explode(',', trim($result->output()));

        if (! $result->successful() || count($parts) < 3) {
            throw new RuntimeException('ffprobe could not read the clip: ' . mb_substr(trim($result->errorOutput()), -300));
        }

        return [(int) $parts[0], (int) $parts[1], $parts[2]];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
