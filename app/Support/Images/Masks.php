<?php

declare(strict_types=1);

namespace App\Support\Images;

use Imagick;

/**
 * The handling every mask shares, white on black: read as grey at a size,
 * an empty one, joined, kept small, and told apart into separate parts.
 */
final class Masks
{
    /**
     * A mask read as grey without transparency, at the given size when there is one.
     */
    public static function grey(string $mask, ?int $width = null, ?int $height = null): Imagick
    {
        $image = new Imagick();
        $image->readImageBlob($mask);
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        if ($width !== null && $height !== null) {
            $image->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        }

        return $image;
    }

    /**
     * A mask with nothing in it.
     */
    public static function black(int $width, int $height): Imagick
    {
        $image = new Imagick();
        $image->newImage($width, $height, 'black');

        return $image;
    }

    /**
     * A mask with nothing in it, at the size of the image, as a png.
     */
    public static function blackFor(string $image): string
    {
        $size = new Imagick();
        $size->pingImageBlob($image);

        return self::png(self::black($size->getImageWidth(), $size->getImageHeight()));
    }

    /**
     * All of the masks together, at the size of the first.
     *
     * @param  non-empty-list<Imagick>  $masks
     */
    public static function union(array $masks): Imagick
    {
        $union = clone $masks[0];

        foreach (array_slice($masks, 1) as $mask) {
            $copy = clone $mask;

            if ($copy->getImageWidth() !== $union->getImageWidth() || $copy->getImageHeight() !== $union->getImageHeight()) {
                $copy->resizeImage($union->getImageWidth(), $union->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
            }

            $union->compositeImage($copy, Imagick::COMPOSITE_LIGHTEN, 0, 0);
        }

        return $union;
    }

    /**
     * The mask scaled to a width, its height following, as a png: small enough to keep on a version.
     */
    public static function small(Imagick $mask, int $width): string
    {
        $copy = clone $mask;
        $copy->resizeImage($width, (int) max(1, round($width * $copy->getImageHeight() / max(1, $copy->getImageWidth()))), Imagick::FILTER_TRIANGLE, 1);

        return self::png($copy);
    }

    public static function png(Imagick $mask): string
    {
        $mask->setImageFormat('png');

        return $mask->getImageBlob();
    }

    /**
     * The separate parts of a mask, each as the indexes of its pixels.
     *
     * @param  list<bool>  $inside
     * @return list<list<int>>
     */
    public static function parts(array $inside, int $width, int $height): array
    {
        $seen = [];
        $parts = [];

        foreach ($inside as $start => $in) {
            if (! $in || isset($seen[$start])) {
                continue;
            }

            $seen[$start] = true;
            $queue = [$start];
            $pixels = [];

            while ($queue !== []) {
                $pixel = array_pop($queue);
                $pixels[] = $pixel;
                $x = $pixel % $width;
                $y = intdiv($pixel, $width);

                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    $next = $ny * $width + $nx;

                    if ($nx >= 0 && $nx < $width && $ny >= 0 && $ny < $height && $inside[$next] && ! isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }

            $parts[] = $pixels;
        }

        return $parts;
    }
}
