<?php

declare(strict_types=1);

namespace App\Support\Images;

use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Lifts a person out of a keyframe drawn on a place, without a model: what
 * differs from the empty place is what was added, it falls apart into
 * separate shapes, and the shape the director clicks is the person, shadow
 * included. Moving puts the empty place back where the person stood and the
 * person down at the new spot.
 */
class PersonCutout
{
    /** Width of the small copy the shapes are found on; the height follows the image. */
    private const int SMALL = 216;

    /** A pixel differing more than this from the place, in any channel, is surely something added: the core of a shape. */
    private const int SURE = 70;

    /** A pixel differing more than this counts as added only near a sure pixel, so redraw noise in the background stays out. */
    private const int THRESHOLD = 36;

    /** How far, in small pixels, a shape may grow from its sure core into pixels that only differ a little. */
    private const int GROW = 4;

    /** The longest vertical gap inside a shape, in small pixels, that is filled where it still differs from the place. */
    private const int GAP = 14;

    /** In such a gap, a pixel differing more than this from the place belongs to the person. */
    private const int FAINT = 16;

    /** Shapes smaller than this share of the image are noise. */
    private const float MIN_AREA = 0.0015;

    /** How far from a shape a click may land, in small pixels. */
    private const int REACH = 6;

    /** At full size: a pixel differing less than this from the place is floor, more than SOLID is the person. */
    private const int CLEAR = 20;

    private const int SOLID = 48;

    /**
     * The shape at a point given as a share of the width and height, or null when nothing was added there.
     *
     * @return array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}}|null
     */
    public function select(string $plate, string $render, float $x, float $y): ?array
    {
        [$width, $height, $added] = $this->added($plate, $render);
        $labels = $this->label($added, $width, $height);
        $label = $this->labelAt($labels, $width, $height, (int) round($x * ($width - 1)), (int) round($y * ($height - 1)));

        if ($label === null) {
            return null;
        }

        [$left, $top, $right, $bottom] = [$width, $height, 0, 0];
        $shape = [];

        foreach ($labels as $index => $value) {
            $inside = $value === $label;
            $shape[$index] = $inside;

            if ($inside) {
                $px = $index % $width;
                $py = intdiv($index, $width);
                [$left, $top, $right, $bottom] = [min($left, $px), min($top, $py), max($right, $px), max($bottom, $py)];
            }
        }

        // Two small pixels of margin, so soft edges and the shadow's fringe can come along; the matte decides what does.
        [$left, $top, $right, $bottom] = [max(0, $left - 2), max(0, $top - 2), min($width - 1, $right + 2), min($height - 1, $bottom + 2)];

        return [
            'box' => ['x' => $left / $width, 'y' => $top / $height, 'width' => ($right - $left + 1) / $width, 'height' => ($bottom - $top + 1) / $height],
            'small' => ['width' => $width, 'height' => $height, 'shape' => $shape],
        ];
    }

    /**
     * The shape as a translucent overlay and as a cut-out, both cropped to its box, as data URLs for the editor.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}}  $shape
     * @return array{overlay: string, cutout: string}
     */
    public function previews(string $plate, string $render, array $shape): array
    {
        $image = new Imagick($render);
        [$x, $y, $w, $h] = $this->pixels($shape['box'], $image->getImageWidth(), $image->getImageHeight());
        $matte = $this->matte($this->placeAt($plate, $image->getImageWidth(), $image->getImageHeight()), $image, $shape);

        $cutout = clone $image;
        $cutout->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $cutout->compositeImage($matte, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $cutout->cropImage($w, $h, $x, $y);
        $cutout->setImagePage(0, 0, 0, 0);

        $overlay = new Imagick();
        $overlay->newImage($image->getImageWidth(), $image->getImageHeight(), new ImagickPixel('rgb(154,140,255)'));
        $alpha = clone $matte;
        $alpha->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.5);
        $overlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $overlay->compositeImage($alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $overlay->cropImage($w, $h, $x, $y);
        $overlay->setImagePage(0, 0, 0, 0);

        return ['overlay' => $this->dataUrl($overlay), 'cutout' => $this->dataUrl($cutout)];
    }

    /**
     * Moves the shape at a point: the place comes back where it stood, and it is put down with its feet
     * moved by `$dx` and `$dy` (shares of the width and height) and scaled by `$scale` around its feet.
     *
     * @return array{x: float, y: float, width: float, height: float}|null where the shape ends up, or null when nothing was added at the point
     */
    public function move(string $plate, string $render, float $x, float $y, float $dx, float $dy, float $scale, string $output): ?array
    {
        $shape = $this->select($plate, $render, $x, $y);

        if ($shape === null) {
            return null;
        }

        $image = new Imagick($render);
        [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];
        $place = $this->placeAt($plate, $width, $height);
        [$bx, $by, $bw, $bh] = $this->pixels($shape['box'], $width, $height);

        $cutout = clone $image;
        $cutout->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $cutout->compositeImage($this->matte($place, $image, $shape), Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $cutout->cropImage($bw, $bh, $bx, $by);
        $cutout->setImagePage(0, 0, 0, 0);

        // Where the person stood, the empty place shows again, a little wider than them so no trace of the shadow stays.
        $hole = clone $place;
        $hole->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $hole->compositeImage($this->fillMask($shape, $width, $height), Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $image->compositeImage($hole, Imagick::COMPOSITE_OVER, 0, 0);

        $scaledWidth = max(1, (int) round($bw * $scale));
        $scaledHeight = max(1, (int) round($bh * $scale));
        $cutout->resizeImage($scaledWidth, $scaledHeight, Imagick::FILTER_LANCZOS, 1);

        $feetX = $bx + $bw / 2 + $dx * $width;
        $feetY = $by + $bh + $dy * $height;
        $left = (int) round($feetX - $scaledWidth / 2);
        $top = (int) round($feetY - $scaledHeight);
        // The person's own shadow stayed behind with the place; a soft one goes under the feet at the new spot.
        $this->contactShadow($image, $feetX, $feetY - $scaledHeight * 0.02, $scaledWidth * 0.6);
        $image->compositeImage($cutout, Imagick::COMPOSITE_OVER, $left, $top);
        $image->setImageFormat('png');
        $image->writeImage($output);

        return ['x' => $left / $width, 'y' => $top / $height, 'width' => $scaledWidth / $width, 'height' => $scaledHeight / $height];
    }

    /**
     * Where the shape nearest a box is now, to check that a redraw left the person where they were put.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public function locate(string $plate, string $render, array $box): ?array
    {
        return $this->select($plate, $render, $box['x'] + $box['width'] / 2, $box['y'] + $box['height'] * 0.6)['box'] ?? null;
    }

    /**
     * A crop around a box with the image's own proportions, so an image model can redraw it at the shot's ratio:
     * about two and a half times the box high, centred on it and kept inside the image. In pixels.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function cropAround(array $box, int $width, int $height): array
    {
        $cropHeight = (int) round(min($height, max($height * 0.35, $box['height'] * $height * 2.5)));
        $cropWidth = (int) round(min($width, $cropHeight * $width / $height));
        $cropHeight = (int) round($cropWidth * $height / $width);
        $centreX = ($box['x'] + $box['width'] / 2) * $width;
        $centreY = ($box['y'] + $box['height'] / 2) * $height;

        return [
            'x' => (int) round(min($width - $cropWidth, max(0, $centreX - $cropWidth / 2))),
            'y' => (int) round(min($height - $cropHeight, max(0, $centreY - $cropHeight / 2))),
            'width' => $cropWidth,
            'height' => $cropHeight,
        ];
    }

    /**
     * Puts a redrawn crop back into the image it came from, blended in over a soft border so no seam shows.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     */
    public function pasteBack(string $image, string $redrawn, array $crop, string $output): void
    {
        $full = new Imagick($image);
        $piece = new Imagick($redrawn);
        $piece->resizeImage($crop['width'], $crop['height'], Imagick::FILTER_LANCZOS, 1);

        // Opaque in the middle, fading out over the outer eighth, so the redraw blends into the image around it.
        $border = max(4, (int) round(min($crop['width'], $crop['height']) / 8));
        $fade = new Imagick();
        $fade->newImage($crop['width'] - 2 * $border, $crop['height'] - 2 * $border, new ImagickPixel('white'));
        $fade->borderImage(new ImagickPixel('black'), $border, $border);
        $fade->blurImage(0, $border / 2);
        $fade->setImageColorspace(Imagick::COLORSPACE_GRAY);

        $piece->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $piece->compositeImage($fade, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $full->compositeImage($piece, Imagick::COMPOSITE_OVER, $crop['x'], $crop['y']);
        $full->setImageFormat('png');
        $full->writeImage($output);
    }

    /**
     * Small copies of both images and, per pixel, whether the render differs from the place there.
     *
     * @return array{int, int, list<bool>}
     */
    private function added(string $plate, string $render): array
    {
        $image = new Imagick($render);
        $height = max(1, (int) round(self::SMALL * $image->getImageHeight() / max(1, $image->getImageWidth())));
        $small = fn(Imagick $source) => tap(clone $source, fn(Imagick $copy) => $copy->resizeImage(self::SMALL, $height, Imagick::FILTER_TRIANGLE, 1))
            ->exportImagePixels(0, 0, self::SMALL, $height, 'RGB', Imagick::PIXEL_CHAR);
        $renderPixels = $small($image);
        $platePixels = $small($this->placeAt($plate, $image->getImageWidth(), $image->getImageHeight()));
        $difference = [];

        for ($i = 0; $i < self::SMALL * $height; $i++) {
            $difference[] = max(
                abs($renderPixels[$i * 3] - $platePixels[$i * 3]),
                abs($renderPixels[$i * 3 + 1] - $platePixels[$i * 3 + 1]),
                abs($renderPixels[$i * 3 + 2] - $platePixels[$i * 3 + 2]),
            );
        }

        // The sure core: clearly different pixels, without single specks.
        $sure = array_map(fn(int $value) => $value > self::SURE, $difference);
        $sure = $this->dilate($this->erode($sure, self::SMALL, $height, 1), self::SMALL, $height, 1);

        // Grow from the core into pixels that differ a little, but only a few steps: soft edges and the shadow
        // come along, redraw noise in the background further away does not.
        $added = $sure;
        $frontier = array_keys(array_filter($sure));

        for ($step = 0; $step < self::GROW && $frontier !== []; $step++) {
            $next = [];

            foreach ($frontier as $index) {
                $px = $index % self::SMALL;
                $py = intdiv($index, self::SMALL);

                foreach ([[$px - 1, $py], [$px + 1, $py], [$px, $py - 1], [$px, $py + 1]] as [$nx, $ny]) {
                    $neighbour = $ny * self::SMALL + $nx;

                    if ($nx >= 0 && $ny >= 0 && $nx < self::SMALL && $ny < $height && ! $added[$neighbour] && $difference[$neighbour] > self::THRESHOLD) {
                        $added[$neighbour] = true;
                        $next[] = $neighbour;
                    }
                }
            }

            $frontier = $next;
        }

        // A face nearly the colour of the wall behind it leaves a notch between hat and collar: short vertical gaps
        // between two parts of a shape are filled where the pixels still differ from the place. Background between
        // an arm and the body is the place itself, so it stays out.
        for ($x = 0; $x < self::SMALL; $x++) {
            $last = null;

            for ($y = 0; $y < $height; $y++) {
                if (! $added[$y * self::SMALL + $x]) {
                    continue;
                }

                if ($last !== null && $y - $last > 1 && $y - $last <= self::GAP) {
                    for ($between = $last + 1; $between < $y; $between++) {
                        $index = $between * self::SMALL + $x;
                        $added[$index] = $added[$index] || $difference[$index] > self::FAINT;
                    }
                }

                $last = $y;
            }
        }

        // Close small gaps inside a person.
        $added = $this->erode($this->dilate($added, self::SMALL, $height, 2), self::SMALL, $height, 2);

        return [self::SMALL, $height, $added];
    }

    /**
     * Numbers the connected shapes; pixels outside any shape, or in shapes too small to matter, get 0.
     *
     * @param  list<bool>  $added
     * @return list<int>
     */
    private function label(array $added, int $width, int $height): array
    {
        $labels = array_fill(0, $width * $height, 0);
        $next = 0;
        $minimum = (int) ceil(self::MIN_AREA * $width * $height);

        foreach ($added as $start => $on) {
            if (! $on || $labels[$start] !== 0) {
                continue;
            }

            $next++;
            $queue = [$start];
            $labels[$start] = $next;
            $members = [];

            while ($queue !== []) {
                $index = array_pop($queue);
                $members[] = $index;
                $px = $index % $width;
                $py = intdiv($index, $width);

                foreach ([[$px - 1, $py], [$px + 1, $py], [$px, $py - 1], [$px, $py + 1]] as [$nx, $ny]) {
                    $neighbour = $ny * $width + $nx;

                    if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height && $added[$neighbour] && $labels[$neighbour] === 0) {
                        $labels[$neighbour] = $next;
                        $queue[] = $neighbour;
                    }
                }
            }

            if (count($members) < $minimum) {
                foreach ($members as $index) {
                    $labels[$index] = -1;
                }
            }
        }

        return array_map(fn(int $label) => max(0, $label), $labels);
    }

    /**
     * The shape under the point, or the nearest one within reach.
     *
     * @param  list<int>  $labels
     */
    private function labelAt(array $labels, int $width, int $height, int $x, int $y): ?int
    {
        for ($radius = 0; $radius <= self::REACH; $radius++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    [$px, $py] = [$x + $dx, $y + $dy];

                    if ($px >= 0 && $py >= 0 && $px < $width && $py < $height && $labels[$py * $width + $px] > 0) {
                        return $labels[$py * $width + $px];
                    }
                }
            }
        }

        return null;
    }

    /**
     * The person's transparency at full size: inside the shape around them, each pixel is as opaque as it differs
     * from the place, so floor around them falls away and soft edges stay soft. The core of the shape stays
     * solid, so a person the colour of the floor behind them gets no holes.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}}  $shape
     */
    private function matte(Imagick $place, Imagick $render, array $shape): Imagick
    {
        ['width' => $smallWidth, 'height' => $smallHeight, 'shape' => $small] = $shape['small'];
        [$width, $height] = [$render->getImageWidth(), $render->getImageHeight()];
        [$x, $y, $w, $h] = $this->pixels($shape['box'], $width, $height);

        $region = $this->dilate($small, $smallWidth, $smallHeight, 1);
        // Solid up to one small pixel from the edge: a face in front of a wall of nearly its colour must not turn see-through.
        $core = $this->erode($small, $smallWidth, $smallHeight, 1);
        $renderPixels = $render->exportImagePixels($x, $y, $w, $h, 'RGB', Imagick::PIXEL_CHAR);
        $placePixels = $place->exportImagePixels($x, $y, $w, $h, 'RGB', Imagick::PIXEL_CHAR);
        $alpha = [];
        // Shadows fall on the floor around the feet: the lowest part of the shape.
        $floorRow = (int) round($h * 0.75);

        for ($row = 0; $row < $h; $row++) {
            $smallRow = min($smallHeight - 1, intdiv(($y + $row) * $smallHeight, $height));

            for ($column = 0; $column < $w; $column++) {
                $smallIndex = $smallRow * $smallWidth + min($smallWidth - 1, intdiv(($x + $column) * $smallWidth, $width));

                if (! $region[$smallIndex]) {
                    $alpha[] = 0;

                    continue;
                }

                $i = ($row * $w + $column) * 3;

                // A shadow is the floor in less light: every channel darker by about the same factor. It stays where it fell.
                // Only near the feet: higher up, a face in front of a bright wall looks the same way.
                if ($row >= $floorRow && $this->isShadow($renderPixels[$i], $renderPixels[$i + 1], $renderPixels[$i + 2], $placePixels[$i], $placePixels[$i + 1], $placePixels[$i + 2])) {
                    $alpha[] = 0;

                    continue;
                }

                // Above the feet the shape itself decides: a face can be nearly the colour of the wall behind it.
                // Around the feet only the solid core does, so the floor between and around them stays behind.
                if ($row < $floorRow ? $small[$smallIndex] : $core[$smallIndex]) {
                    $alpha[] = 255;

                    continue;
                }

                $difference = max(
                    abs($renderPixels[$i] - $placePixels[$i]),
                    abs($renderPixels[$i + 1] - $placePixels[$i + 1]),
                    abs($renderPixels[$i + 2] - $placePixels[$i + 2]),
                );
                $alpha[] = (int) round(255 * min(1, max(0, ($difference - self::CLEAR) / (self::SOLID - self::CLEAR))));
            }
        }

        $piece = new Imagick();
        $piece->newImage($w, $h, new ImagickPixel('black'));
        $piece->importImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR, $alpha);
        $piece->blurImage(0, 0.6);

        $matte = new Imagick();
        $matte->newImage($width, $height, new ImagickPixel('black'));
        $matte->compositeImage($piece, Imagick::COMPOSITE_OVER, $x, $y);
        $matte->setImageColorspace(Imagick::COLORSPACE_GRAY);

        return $matte;
    }

    /**
     * Whether a pixel is the place in shadow: darker in every channel, by about the same factor, and clearly darker.
     */
    private function isShadow(int $r, int $g, int $b, int $pr, int $pg, int $pb): bool
    {
        if ($pr < 24 || $pg < 24 || $pb < 24 || $r > $pr + 6 || $g > $pg + 6 || $b > $pb + 6) {
            return false;
        }

        $ratios = [$r / $pr, $g / $pg, $b / $pb];

        return max($ratios) < 0.92 && max($ratios) - min($ratios) < 0.12;
    }

    /**
     * A soft, neutral contact shadow under feet at a point, so a person put down somewhere else does not float.
     */
    private function contactShadow(Imagick $image, float $feetX, float $feetY, float $personWidth): void
    {
        $width = max(8, (int) round($personWidth * 1.1));
        $height = max(4, (int) round($width * 0.22));
        $pad = $height * 2;

        $shadow = new Imagick();
        $shadow->newImage($width + 2 * $pad, $height + 2 * $pad, new ImagickPixel('transparent'));
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('rgba(0,0,0,0.35)'));
        // Whole pixels: ImageMagick's drawing language reads a locale's decimal comma as an error.
        $draw->ellipse($pad + intdiv($width, 2), $pad + intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), 0, 360);
        $shadow->drawImage($draw);
        $shadow->blurImage(0, $height / 2);
        $image->compositeImage($shadow, Imagick::COMPOSITE_OVER, (int) round($feetX - $width / 2 - $pad), (int) round($feetY - $height / 2 - $pad));
    }

    /**
     * Where the empty place goes back in: the shape grown a few small pixels with a soft edge, so the whole shadow goes.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}}  $shape
     */
    private function fillMask(array $shape, int $width, int $height): Imagick
    {
        ['width' => $smallWidth, 'height' => $smallHeight, 'shape' => $small] = $shape['small'];
        $grown = $this->dilate($small, $smallWidth, $smallHeight, 3);

        $mask = new Imagick();
        $mask->newImage($smallWidth, $smallHeight, new ImagickPixel('black'));
        $mask->importImagePixels(0, 0, $smallWidth, $smallHeight, 'I', Imagick::PIXEL_CHAR, array_map(fn(bool $on) => $on ? 255 : 0, $grown));
        $mask->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        $mask->blurImage(0, 3);
        $mask->setImageColorspace(Imagick::COLORSPACE_GRAY);

        return $mask;
    }

    private function placeAt(string $plate, int $width, int $height): Imagick
    {
        $place = new Imagick($plate);

        if ($place->getImageWidth() !== $width || $place->getImageHeight() !== $height) {
            $place->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        return $place;
    }

    /**
     * @param  list<bool>  $mask
     * @return list<bool>
     */
    private function dilate(array $mask, int $width, int $height, int $steps): array
    {
        for ($step = 0; $step < $steps; $step++) {
            $grown = $mask;

            foreach ($mask as $index => $on) {
                if ($on) {
                    continue;
                }

                $px = $index % $width;
                $py = intdiv($index, $width);
                $grown[$index] = ($px > 0 && $mask[$index - 1]) || ($px < $width - 1 && $mask[$index + 1]) || ($py > 0 && $mask[$index - $width]) || ($py < $height - 1 && $mask[$index + $width]);
            }

            $mask = $grown;
        }

        return $mask;
    }

    /**
     * @param  list<bool>  $mask
     * @return list<bool>
     */
    private function erode(array $mask, int $width, int $height, int $steps): array
    {
        return array_map(fn(bool $on) => ! $on, $this->dilate(array_map(fn(bool $on) => ! $on, $mask), $width, $height, $steps));
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{int, int, int, int}
     */
    private function pixels(array $box, int $width, int $height): array
    {
        $x = max(0, (int) floor($box['x'] * $width));
        $y = max(0, (int) floor($box['y'] * $height));

        return [$x, $y, min($width - $x, max(1, (int) ceil($box['width'] * $width))), min($height - $y, max(1, (int) ceil($box['height'] * $height)))];
    }

    private function dataUrl(Imagick $image): string
    {
        if ($image->getImageWidth() > 540) {
            $image->resizeImage(540, 0, Imagick::FILTER_TRIANGLE, 1);
        }

        $image->setImageFormat('png');

        return 'data:image/png;base64,' . base64_encode($image->getImageBlob());
    }
}
