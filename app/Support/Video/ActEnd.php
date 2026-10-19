<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

/**
 * Finds where the act in a clip ends: Ray plays the act at its own speed and
 * then eases into the pinned end frame, almost still. The act is over from
 * the first frame after which the picture moves only a little, compared
 * with how fast it moves during the act. This is where the shot is cut.
 */
class ActEnd
{
    /** The clip is compared at this rate, a frame at a time. */
    private const int FPS = 24;

    /** Frames kept past the end of the act, so the end frame shows for a moment. */
    private const int TAIL_FRAMES = 4;

    /** The shortest act kept, in seconds. */
    private const float SHORTEST = 1.0;

    /** The movement is averaged over this many frames, so one jittery frame does not count. */
    private const int SMOOTHING = 6;

    /** Still is moving at most this share of the fastest movement in the clip, and at most a small floor for noise. */
    private const float STILL_SHARE = 0.3;

    private const float NOISE = 0.2;

    private const int SIZE = 32;

    /**
     * The seconds from the start of the clip to the end of the act, or null when ffmpeg cannot read the clip.
     */
    public function seconds(string $path): ?float
    {
        $result = Process::timeout(120)->run([
            Config::get('pipeline.ffmpeg.binary'), '-v', 'error', '-i', $path,
            '-vf', 'fps=' . self::FPS . ',scale=' . self::SIZE . ':' . self::SIZE . ',format=gray',
            '-f', 'rawvideo', '-',
        ]);

        $pixels = self::SIZE * self::SIZE;
        $bytes = $result->output();

        if (! $result->successful() || strlen($bytes) < $pixels * 2) {
            return null;
        }

        $frames = array_map(fn(string $frame) => array_values((array) unpack('C*', $frame)), str_split(substr($bytes, 0, intdiv(strlen($bytes), $pixels) * $pixels), $pixels));
        $moves = [0.0];

        foreach (array_slice($frames, 1, null, true) as $index => $frame) {
            $moves[] = array_sum(array_map(fn(int $a, int $b) => abs($a - $b), $frame, $frames[$index - 1])) / $pixels;
        }

        $smooth = array_map(fn(int $index) => array_sum(array_slice($moves, max(0, $index - self::SMOOTHING + 1), min(self::SMOOTHING, $index + 1))) / min(self::SMOOTHING, $index + 1), array_keys($moves));
        $still = max(self::NOISE, max($smooth) * self::STILL_SHARE);
        $end = count($frames) - 1;

        while ($end > 0 && $smooth[$end - 1] <= $still) {
            $end--;
        }

        return round(min(count($frames), max(self::SHORTEST * self::FPS, $end + self::TAIL_FRAMES)) / self::FPS, 3);
    }
}
