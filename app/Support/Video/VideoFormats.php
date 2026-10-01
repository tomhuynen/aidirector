<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;

/**
 * The aspect ratios and resolutions the video model can deliver, and the
 * frame size of each combination.
 */
class VideoFormats
{
    private const SHORT_EDGES = ['480p' => 480, '720p' => 720, '1080p' => 1080, '4K' => 2160];

    private const NAMES = [
        '16:9' => 'Landscape',
        '9:16' => 'Portrait',
        '1:1' => 'Square',
        '4:3' => 'Classic',
        '3:4' => 'Classic portrait',
        '21:9' => 'Cinematic',
        '9:21' => 'Tall',
    ];

    /**
     * @return list<string>
     */
    public static function aspectRatios(): array
    {
        return array_values(Config::get('pipeline.video.aspect_ratios'));
    }

    /**
     * @return list<string>
     */
    public static function resolutions(): array
    {
        return array_values(Config::get('pipeline.video.resolutions'));
    }

    /**
     * Width and height in pixels: the resolution sets the short edge, the
     * aspect ratio the long one, rounded to an even number as video needs.
     *
     * @return array{width: int, height: int}
     */
    public static function size(string $aspectRatio, string $resolution): array
    {
        [$w, $h] = array_map('intval', explode(':', $aspectRatio));
        $short = self::SHORT_EDGES[$resolution] ?? 720;
        $long = (int) (2 * round($short * max($w, $h) / min($w, $h) / 2));

        return $w >= $h ? ['width' => $long, 'height' => $short] : ['width' => $short, 'height' => $long];
    }

    /**
     * Everything the format picker shows.
     *
     * @return array{aspectRatios: list<array{value: string, name: string}>, resolutions: list<string>, sizes: array<string, array{width: int, height: int}>}
     */
    public static function catalogue(): array
    {
        $sizes = [];

        foreach (self::aspectRatios() as $ratio) {
            foreach (self::resolutions() as $resolution) {
                $sizes["{$ratio} {$resolution}"] = self::size($ratio, $resolution);
            }
        }

        return [
            'aspectRatios' => array_map(fn(string $ratio) => ['value' => $ratio, 'name' => self::NAMES[$ratio] ?? $ratio], self::aspectRatios()),
            'resolutions' => self::resolutions(),
            'sizes' => $sizes,
        ];
    }
}
