<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

/**
 * Finds the cuts in a clip that holds several shots: a cut is a frame that
 * differs from the one before far more than any movement inside a shot.
 */
class SceneCuts
{
    /** The clip is compared at this rate, a frame at a time. */
    private const int FPS = 24;

    private const int SIZE = 32;

    /** The fewest frames between two cuts. */
    private const int SHORTEST_SHOT = 12;

    /**
     * When each of `$shots` shots starts, in seconds, the first at 0: the
     * largest changes from one frame to the next, in order; and how long the
     * clip is. Null when ffmpeg cannot read the clip.
     *
     * @return array{starts: list<float>, seconds: float}|null
     */
    public function read(string $path, int $shots): ?array
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

        $frames = str_split(substr($bytes, 0, intdiv(strlen($bytes), $pixels) * $pixels), $pixels);
        $changes = [];

        foreach (array_slice($frames, 1, null, true) as $index => $frame) {
            $changes[$index] = array_sum(array_map(fn(int $a, int $b) => abs($a - $b), (array) unpack('C*', $frame), (array) unpack('C*', $frames[$index - 1]))) / $pixels;
        }

        arsort($changes);
        $cuts = [];

        foreach (array_keys($changes) as $index) {
            if (count($cuts) === $shots - 1) {
                break;
            }

            if ($index >= self::SHORTEST_SHOT && $index <= count($frames) - self::SHORTEST_SHOT && collect($cuts)->every(fn(int $cut) => abs($cut - $index) >= self::SHORTEST_SHOT)) {
                $cuts[] = $index;
            }
        }

        sort($cuts);

        return [
            'starts' => [0.0, ...array_map(fn(int $cut) => round($cut / self::FPS, 3), $cuts)],
            'seconds' => round(count($frames) / self::FPS, 3),
        ];
    }
}
