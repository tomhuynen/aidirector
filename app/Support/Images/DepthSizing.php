<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\Shots\KeyframeLayout;

/**
 * Sizes a box by the depth of the place where it stands: someone further
 * from the camera is smaller, in proportion to how far they stand. The size
 * at one spot is known from where the same person stood before.
 *
 * @phpstan-import-type Box from KeyframeLayout
 */
final class DepthSizing
{
    /**
     * The box with its height and width set for the depth at its feet, from
     * the box the same person or thing had at another spot. Its feet stay
     * where they are, in the middle of its bottom edge. The box may reach
     * past the edge of the frame, for someone partly out of the shot.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     * @param  Box  $before
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function fit(array $box, array $before, DepthGrid $depth): array
    {
        $here = self::depthAtFeet($box, $depth);
        $there = self::depthAtFeet($before, $depth);

        if ($here <= 0.0 || $there <= 0.0) {
            return $box;
        }

        $scale = $there / $here;
        $height = ($before[2] - $before[0]) * $scale;
        $width = ($before[3] - $before[1]) * $scale;
        $middle = ($box[1] + $box[3]) / 2;
        $bottom = $box[2];

        return [
            (int) round($bottom - $height),
            (int) round($middle - $width / 2),
            $bottom,
            (int) round($middle + $width / 2),
        ];
    }

    /**
     * The depth where the feet stand, at the edge of the frame when they are past it.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     */
    private static function depthAtFeet(array $box, DepthGrid $depth): float
    {
        return $depth->at(max(0, min(1, ($box[1] + $box[3]) / 2000)), max(0, min(0.999, $box[2] / 1000)));
    }
}
