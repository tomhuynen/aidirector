<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Storage;
use Imagick;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Measures in code, without a model, whether an edited keyframe kept the
 * background of the image it was drawn on. The image model sometimes zooms
 * or pans the whole picture while editing it. Things that change on purpose,
 * like a person added or a load that is lowered, change only their own part;
 * a zoom or pan moves everything, so the picture lines up better with the
 * base once it is shifted or scaled back. That is what counts as moved.
 */
class BackgroundDrift
{
    private const int WIDTH = 72;

    private const int HEIGHT = 128;

    private const int TILE = 8;

    /** A tile with less edge than this is flat, like sky or floor, and looks the same when shifted. */
    private const float MIN_EDGE = 0.01;

    /** A tile that differs less than this on average stayed in place. */
    private const float MAX_DIFFERENCE = 0.03;

    /** Lined up this much better after a shift or zoom, the whole picture moved. */
    private const float MIN_GAIN = 0.1;

    /** @var list<float> */
    private const array ZOOMS = [0.94, 0.96, 0.98, 1.0, 1.02, 1.04, 1.06];

    /** The largest shift tried, in pixels of the small image: about 8% of its width. */
    private const int MAX_SHIFT = 6;

    /**
     * How the render lines up with its base: `stillness` is the share of the
     * background tiles with structure that stayed in place, from 0 to 1;
     * `aligned` the best share after shifting or zooming the render back;
     * `moved` whether that lines up clearly better, so the whole picture
     * moved. With at least `$still` of it in place, nothing is searched.
     *
     * @return array{stillness: float, aligned: float, moved: bool}
     */
    public function measure(Media $base, Media $render, float $still = 0.85): array
    {
        $basePixels = $this->pixels($base);
        $renderPixels = $this->pixels($render);
        $tiles = $this->structuredTiles($basePixels);
        $stillness = $this->share($basePixels, $renderPixels, $tiles, 1.0, 0, 0);

        if ($stillness >= $still) {
            return ['stillness' => $stillness, 'aligned' => $stillness, 'moved' => false];
        }

        $aligned = $stillness;

        foreach (self::ZOOMS as $zoom) {
            for ($shiftX = -self::MAX_SHIFT; $shiftX <= self::MAX_SHIFT; $shiftX++) {
                for ($shiftY = -self::MAX_SHIFT; $shiftY <= self::MAX_SHIFT; $shiftY++) {
                    $aligned = max($aligned, $this->share($basePixels, $renderPixels, $tiles, $zoom, $shiftX, $shiftY));
                }
            }
        }

        return ['stillness' => $stillness, 'aligned' => $aligned, 'moved' => $aligned - $stillness >= self::MIN_GAIN];
    }

    /**
     * The tiles of the base with structure; a base without any counts as still everywhere.
     *
     * @param  list<float>  $pixels
     * @return list<array{int, int}>
     */
    private function structuredTiles(array $pixels): array
    {
        $tiles = [];

        for ($tileY = 0; $tileY < self::HEIGHT / self::TILE; $tileY++) {
            for ($tileX = 0; $tileX < self::WIDTH / self::TILE; $tileX++) {
                $edge = 0.0;

                for ($y = $tileY * self::TILE; $y < ($tileY + 1) * self::TILE; $y++) {
                    for ($x = $tileX * self::TILE + 1; $x < ($tileX + 1) * self::TILE; $x++) {
                        $edge += abs($pixels[$y * self::WIDTH + $x] - $pixels[$y * self::WIDTH + $x - 1]);
                    }
                }

                if ($edge / self::TILE ** 2 > self::MIN_EDGE) {
                    $tiles[] = [$tileX, $tileY];
                }
            }
        }

        return $tiles;
    }

    /**
     * The share of the tiles that match when the render is scaled by `$zoom` around its centre and shifted.
     *
     * @param  list<float>  $base
     * @param  list<float>  $render
     * @param  list<array{int, int}>  $tiles
     */
    private function share(array $base, array $render, array $tiles, float $zoom, int $shiftX, int $shiftY): float
    {
        if ($tiles === []) {
            return 1.0;
        }

        $still = 0;

        foreach ($tiles as [$tileX, $tileY]) {
            $difference = 0.0;

            for ($y = $tileY * self::TILE; $y < ($tileY + 1) * self::TILE; $y++) {
                $sourceY = (int) round(self::HEIGHT / 2 + ($y - self::HEIGHT / 2) * $zoom + $shiftY);

                for ($x = $tileX * self::TILE; $x < ($tileX + 1) * self::TILE; $x++) {
                    $sourceX = (int) round(self::WIDTH / 2 + ($x - self::WIDTH / 2) * $zoom + $shiftX);
                    $value = $sourceX < 0 || $sourceY < 0 || $sourceX >= self::WIDTH || $sourceY >= self::HEIGHT ? 0.5 : $render[$sourceY * self::WIDTH + $sourceX];
                    $difference += abs($base[$y * self::WIDTH + $x] - $value);
                }
            }

            if ($difference / self::TILE ** 2 < self::MAX_DIFFERENCE) {
                $still++;
            }
        }

        return $still / count($tiles);
    }

    /**
     * The image small, grey and slightly soft, so only the layout counts and not the fine detail the model redraws.
     *
     * @return list<float>
     */
    private function pixels(Media $media): array
    {
        $image = new Imagick();
        $image->readImageBlob((string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $image->resizeImage(self::WIDTH, self::HEIGHT, Imagick::FILTER_TRIANGLE, 1);
        $image->blurImage(0, 0.7);

        /** @var list<float> $pixels */
        $pixels = $image->exportImagePixels(0, 0, self::WIDTH, self::HEIGHT, 'I', Imagick::PIXEL_FLOAT);
        $image->clear();

        return $pixels;
    }
}
