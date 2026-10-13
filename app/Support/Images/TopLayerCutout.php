<?php

declare(strict_types=1);

namespace App\Support\Images;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Imagick;
use ImagickDraw;
use ImagickKernel;
use ImagickPixel;
use Throwable;

/**
 * Lifts a person or a thing out of the top layer of a keyframe drawn on a
 * place: the mask of what lies on top, made the way the composite makes it,
 * falls apart into separate shapes, and the shape the director clicks is the
 * one that moves, a person's shadow included. Moving puts the empty place
 * back where it stood and it down at the new spot. Without a mask, what
 * differs from the empty place counts as added.
 */
class TopLayerCutout
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

    /** How far to either side of a person, in their widths, a shadow they leave on the floor is looked for. */
    private const float SHADOW_REACH = 0.75;

    /** How far from a shape a click may land, in small pixels. */
    private const int REACH = 6;

    /** At full size: a pixel differing less than this from the place is floor, more than SOLID is the person. */
    private const int CLEAR = 20;

    private const int SOLID = 48;

    /** The width a top-layer mask is kept at on a version; fine enough for hair and fingers once scaled up and softened. */
    private const int MASK_WIDTH = 432;

    /** What lies on top of the place, people and things, white on black; without it, what differs from the place counts. */
    private ?string $topLayerMask = null;

    /** After a move with a top-layer mask: the mask of the moved image. */
    private ?string $movedTopLayer = null;

    /** Of what lies on top, the things, such as a helicopter flying in, white on black; a shape mostly in it is a thing, not a person. */
    private ?string $thingsMask = null;

    /** After a move with a things mask: the things mask of the moved image. */
    private ?string $movedThings = null;

    /** Whether the last move moved a thing rather than a person. */
    private bool $movedThing = false;

    /**
     * The whole cut-outs of what was moved by hand before on this image, also the parts outside the frame.
     *
     * @var list<array{image: string, box: array{x: float, y: float, width: float, height: float}, thing: bool}>
     */
    private array $layers = [];

    /** @var array{image: string, box: array{x: float, y: float, width: float, height: float}, thing: bool}|null after a move: the whole cut-out of what moved, where it lies now */
    private ?array $movedLayer = null;

    /** After a move: which of the layers it picked up, if any. */
    private ?int $usedLayer = null;

    /** After a move: where it stood and its shadow, a little wider, without where it is now; white there, as a png. */
    private ?string $vacated = null;

    /** Finds what can be moved in a redrawn image; without it, what differs from the place counts there. @var (Closure(string): string)|null */
    private ?Closure $finder = null;

    /**
     * The same, finding people in the given mask instead of by what differs from the place: shoes and dark
     * trousers on a floor of nearly their colour then come along whole.
     */
    public function withTopLayer(?string $mask): static
    {
        $copy = clone $this;
        $copy->topLayerMask = $mask;

        return $copy;
    }

    /**
     * The same, picking up the whole cut-out of what was moved by hand before, also the part outside the frame,
     * instead of cutting it out of the image again.
     *
     * @param  list<array{image: string, box: array{x: float, y: float, width: float, height: float}, thing: bool}>  $layers
     */
    public function withLayers(array $layers): static
    {
        $copy = clone $this;
        $copy->layers = $layers;

        return $copy;
    }

    /**
     * The same, knowing which of what lies on top are things: those are moved without a shadow on the floor.
     */
    public function withThings(?string $mask): static
    {
        $copy = clone $this;
        $copy->thingsMask = $mask;

        return $copy;
    }

    /**
     * The same, finding what can be moved in an image redrawn after a move with the given finder, which
     * makes the mask the same way the composite does.
     *
     * @param  Closure(string): string  $finder  from the path of an image to its mask
     */
    public function withFinder(Closure $finder): static
    {
        $copy = clone $this;
        $copy->finder = $finder;

        return $copy;
    }

    /**
     * What can be moved in an image, by the finder; null without one or when it fails, and then what differs from the place counts.
     */
    private function findIn(string $image): ?string
    {
        if ($this->finder === null) {
            return null;
        }

        try {
            return ($this->finder)($image);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A top-layer mask made small enough to keep on a version.
     *
     * @param  list<string>  $also  masks of what else came from the drawing, joined into it
     */
    public static function keptSmall(string $mask, array $also = []): string
    {
        $image = Masks::grey($mask);

        // What else came from the drawing in this keyframe, such as a helicopter flying in or a phone lifted off its hook, can be moved like a person.
        return Masks::small(Masks::union([$image, ...array_map(fn(string $area) => Masks::grey($area), $also)]), self::MASK_WIDTH);
    }

    /**
     * The shape at a point given as a share of the width and height, or null when nothing was added there.
     *
     * @return array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing: bool, layer?: int}|null
     */
    public function select(string $plate, string $render, float $x, float $y): ?array
    {
        if (($layer = $this->layerAt($render, $x, $y)) !== null) {
            return $layer;
        }

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
            'thing' => $this->isThing($shape, $width, $height),
        ];
    }

    /**
     * The layer at a point, as a shape: its whole box, also outside the frame, and the part of it in the frame.
     *
     * @return array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing: bool, layer: int}|null
     */
    private function layerAt(string $render, float $x, float $y): ?array
    {
        foreach ($this->layers as $index => $layer) {
            $box = $layer['box'];

            if ($x < $box['x'] || $y < $box['y'] || $x > $box['x'] + $box['width'] || $y > $box['y'] + $box['height']) {
                continue;
            }

            $alpha = $this->alphaOf($layer['image']);
            $px = (int) floor(($x - $box['x']) / $box['width'] * ($alpha->getImageWidth() - 1));
            $py = (int) floor(($y - $box['y']) / $box['height'] * ($alpha->getImageHeight() - 1));

            if ($alpha->getImagePixelColor($px, $py)->getColorValue(Imagick::COLOR_RED) < 0.5) {
                continue;
            }

            $size = new Imagick();
            $size->pingImage($render);
            $width = self::SMALL;
            $height = max(1, (int) round(self::SMALL * $size->getImageHeight() / max(1, $size->getImageWidth())));
            $alpha->resizeImage(max(1, (int) round($box['width'] * $width)), max(1, (int) round($box['height'] * $height)), Imagick::FILTER_TRIANGLE, 1);
            $small = Masks::black($width, $height);
            $small->compositeImage($alpha, Imagick::COMPOSITE_COPY, (int) round($box['x'] * $width), (int) round($box['y'] * $height));
            $shape = array_map(fn(int $value) => $value > 127, $small->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR));

            return ['box' => $box, 'small' => ['width' => $width, 'height' => $height, 'shape' => $shape], 'thing' => $layer['thing'], 'layer' => $index];
        }

        return null;
    }

    /**
     * How opaque a cut-out is, as grey: white where it is solid.
     */
    private function alphaOf(string $image): Imagick
    {
        $alpha = new Imagick();
        $alpha->readImageBlob($image);
        $alpha->setImageAlphaChannel(Imagick::ALPHACHANNEL_EXTRACT);
        $alpha->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        return $alpha;
    }

    /**
     * Whether a shape is mostly a thing, such as a helicopter, rather than a person with what they hold.
     *
     * @param  list<bool>  $shape
     */
    private function isThing(array $shape, int $width, int $height): bool
    {
        if ($this->thingsMask === null) {
            return false;
        }

        $things = $this->maskAt($width, $height, $this->thingsMask)->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $inside = array_keys(array_filter($shape));
        $onThing = count(array_filter($inside, fn(int $index) => $things[$index] > 127));

        return $inside !== [] && $onThing * 2 > count($inside);
    }

    /**
     * The shape as a translucent overlay and as a cut-out, both cropped to its box, as data URLs for the editor.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool, layer?: int}  $shape
     * @return array{overlay: string, cutout: string}
     */
    public function previews(string $plate, string $render, array $shape): array
    {
        // A layer is shown whole, also what lies outside the frame, so it can be dragged back in.
        if (isset($shape['layer'])) {
            $cutout = new Imagick();
            $cutout->readImageBlob($this->layers[$shape['layer']]['image']);
            $overlay = new Imagick();
            $overlay->newImage($cutout->getImageWidth(), $cutout->getImageHeight(), new ImagickPixel('rgb(154,140,255)'));
            $alpha = $this->alphaOf($this->layers[$shape['layer']]['image']);
            $alpha->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.5);
            $overlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $overlay->compositeImage($alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

            return ['overlay' => $this->dataUrl($overlay), 'cutout' => $this->dataUrl($cutout)];
        }

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
     * With the place's depth, what stands in front of the new spot hides the person there.
     *
     * @return array{x: float, y: float, width: float, height: float}|null where the shape ends up, or null when nothing was added at the point
     */
    public function move(string $plate, string $render, float $x, float $y, float $dx, float $dy, float $scale, string $output, ?DepthGrid $depth = null): ?array
    {
        $shape = $this->select($plate, $render, $x, $y);

        if ($shape === null) {
            return null;
        }

        $image = new Imagick($render);
        [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];
        $place = $this->placeAt($plate, $width, $height);

        if (isset($shape['layer'])) {
            // Picked up whole, as it was put down before, also what lies outside the frame.
            $cutout = new Imagick();
            $cutout->readImageBlob($this->layers[$shape['layer']]['image']);
            [$bw, $bh] = [$cutout->getImageWidth(), $cutout->getImageHeight()];
            [$bx, $by] = [(int) round($shape['box']['x'] * $width), (int) round($shape['box']['y'] * $height)];
            $piece = $this->alphaOf($this->layers[$shape['layer']]['image']);
        } else {
            [$bx, $by, $bw, $bh] = $this->pixels($shape['box'], $width, $height);
            $matte = $this->matte($place, $image, $shape);
            $cutout = clone $image;
            $cutout->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $cutout->compositeImage($matte, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $cutout->cropImage($bw, $bh, $bx, $by);
            $cutout->setImagePage(0, 0, 0, 0);
            $piece = clone $matte;
            $piece->setImageAlphaChannel(Imagick::ALPHACHANNEL_OFF);
            $piece->cropImage($bw, $bh, $bx, $by);
            $piece->setImagePage(0, 0, 0, 0);
        }

        // Where the person stood, the empty place shows again, a little wider than them, and their shadow with it.
        $hole = clone $place;
        $hole->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $holeMask = $this->holeMask($place, $image, $shape, $width, $height);
        $hole->compositeImage($holeMask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $image->compositeImage($hole, Imagick::COMPOSITE_OVER, 0, 0);

        $scaledWidth = max(1, (int) round($bw * $scale));
        $scaledHeight = max(1, (int) round($bh * $scale));
        $cutout->resizeImage($scaledWidth, $scaledHeight, Imagick::FILTER_LANCZOS, 1);

        $feetX = $bx + $bw / 2 + $dx * $width;
        $feetY = $by + $bh + $dy * $height;
        $left = (int) round($feetX - $scaledWidth / 2);
        $top = (int) round($feetY - $scaledHeight);
        // Kept whole, before anything in front hides part of it and before the frame cuts it off.
        $whole = clone $cutout;
        $whole->setImageFormat('png');

        if ($depth !== null) {
            $this->hideBehind($cutout, $depth, $left, $top, $width, $height, $depth->at($feetX / $width, min(0.999, $feetY / $height)));
        }

        $image->compositeImage($cutout, Imagick::COMPOSITE_OVER, $left, $top);
        $image->setImageFormat('png');
        $image->writeImage($output);
        $this->movedTopLayer = $this->topLayerMask === null ? null : $this->movedMask($this->topLayerMask, true, $shape, $piece, [$left, $top, $scaledWidth, $scaledHeight], $width, $height);
        $this->movedThing = $shape['thing'];
        $this->movedThings = $this->thingsMask === null ? null : $this->movedMask($this->thingsMask, $shape['thing'], $shape, $piece, [$left, $top, $scaledWidth, $scaledHeight], $width, $height);
        $this->movedLayer = ['image' => $whole->getImageBlob(), 'box' => ['x' => $left / $width, 'y' => $top / $height, 'width' => $scaledWidth / $width, 'height' => $scaledHeight / $height], 'thing' => $shape['thing']];
        $this->usedLayer = $shape['layer'] ?? null;
        $this->vacated = $this->vacatedMask($holeMask, $cutout, $left, $top, $width, $height);

        return ['x' => $left / $width, 'y' => $top / $height, 'width' => $scaledWidth / $width, 'height' => $scaledHeight / $height];
    }

    /**
     * The top-layer mask of the image the last move made: what moved taken out where it stood and put in where it
     * was put, so the next click finds it without the cut-out model.
     */
    public function movedTopLayer(): ?string
    {
        return $this->movedTopLayer;
    }

    /**
     * The whole cut-out of what the last move moved, where it lies now, also outside the frame.
     *
     * @return array{image: string, box: array{x: float, y: float, width: float, height: float}, thing: bool}|null
     */
    public function movedLayer(): ?array
    {
        return $this->movedLayer;
    }

    /**
     * Which of the layers the last move picked up, if any: it is replaced by the moved one.
     */
    public function usedLayer(): ?int
    {
        return $this->usedLayer;
    }

    /**
     * Whether the last move moved a thing, such as a helicopter, rather than a person.
     */
    public function movedThing(): bool
    {
        return $this->movedThing;
    }

    /**
     * The things mask of the image the last move made, the same way.
     */
    public function movedThings(): ?string
    {
        return $this->movedThings;
    }

    /**
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool}  $shape
     * @param  bool  $putDown  whether what was moved belongs in this mask at its new spot
     * @param  Imagick  $piece  how solid what was moved is, grey, at its own size
     * @param  array{int, int, int, int}  $to  where it was put: left, top, width, height
     */
    private function movedMask(string $source, bool $putDown, array $shape, Imagick $piece, array $to, int $width, int $height): string
    {
        $mask = $this->maskAt($width, $height, $source);
        $gone = $this->fillMask($shape, $width, $height);
        $gone->negateImage(false);
        $mask->compositeImage($gone, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        if (! $putDown) {
            $mask->setImageFormat('png');

            return self::keptSmall($mask->getImageBlob());
        }

        // Its own matte, moved and scaled as it was.
        $person = clone $piece;
        $person->resizeImage(max(1, $to[2]), max(1, $to[3]), Imagick::FILTER_TRIANGLE, 1);
        $mask->compositeImage($person, Imagick::COMPOSITE_LIGHTEN, $to[0], $to[1]);
        $mask->setImageFormat('png');

        return self::keptSmall($mask->getImageBlob());
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
     * `$times` the box high, centred on it and kept inside the image. In pixels.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function cropAround(array $box, int $width, int $height, float $times = 2.5): array
    {
        $cropHeight = (int) round(min($height, max($height * 0.35, $box['height'] * $height * $times)));
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
     * Puts back only the person from a redrawn crop, with their feet where they were put: the rest of the crop is
     * not used, so a redraw that shifted or zoomed a little leaves no double outlines. Where the person stood before
     * the redraw, the place shows again. Falls back to blending the whole crop when the person cannot be found in it.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     * @param  array{x: float, y: float, width: float, height: float}  $box  where the person was put
     */
    public function pastePerson(string $plate, string $moved, string $redrawn, array $crop, array $box, string $output): void
    {
        $image = new Imagick($moved);
        [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];

        // The redraw pasted in plainly, only to find the person in it.
        $piece = new Imagick($redrawn);
        $piece->resizeImage($crop['width'], $crop['height'], Imagick::FILTER_LANCZOS, 1);
        $withRedraw = clone $image;
        $withRedraw->compositeImage($piece, Imagick::COMPOSITE_OVER, $crop['x'], $crop['y']);
        $withRedraw->setImageFormat('png');
        $redrawnPath = storage_path('app/tmp/redrawn-' . Str::random(12) . '.png');
        @mkdir(dirname($redrawnPath), 0755, true);
        $withRedraw->writeImage($redrawnPath);

        try {
            $centre = [$box['x'] + $box['width'] / 2, $box['y'] + $box['height'] * 0.6];
            $before = $this->select($plate, $moved, ...$centre);
            $redrawnCutout = $this->topLayerMask !== null ? $this->withTopLayer($this->findIn($redrawnPath)) : $this;
            $after = $redrawnCutout->select($plate, $redrawnPath, ...$centre);

            if ($before === null || $after === null) {
                $this->pasteBack($moved, $redrawn, $crop, $output);

                return;
            }

            $place = $this->placeAt($plate, $width, $height);

            // Where the person stood before the redraw, the place shows again.
            $hole = clone $place;
            $hole->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $hole->compositeImage($this->holeMask($place, $image, $before, $width, $height), Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $image->compositeImage($hole, Imagick::COMPOSITE_OVER, 0, 0);

            // The redrawn person alone, moved so their feet land where they were put.
            [$ax, $ay, $aw, $ah] = $this->pixels($after['box'], $width, $height);
            $matte = $redrawnCutout->matte($place, $withRedraw, $after);
            $shiftX = (int) round(($box['x'] + $box['width'] / 2) * $width - ($ax + $aw / 2));
            $shiftY = (int) round(($box['y'] + $box['height']) * $height - ($ay + $ah));

            // The shadow the redraw gave them on the ground comes along: darker than the place, right next to them, inside the redrawn crop.
            $shadow = $this->shadowIn($withRedraw, $place, $matte, $crop);
            $shade = clone $withRedraw;
            $shade->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $shade->compositeImage($shadow, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $image->compositeImage($shade, Imagick::COMPOSITE_OVER, $shiftX, $shiftY);

            $person = clone $withRedraw;
            $person->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $person->compositeImage($matte, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $person->cropImage($aw, $ah, $ax, $ay);
            $person->setImagePage(0, 0, 0, 0);
            $image->compositeImage($person, Imagick::COMPOSITE_OVER, $ax + $shiftX, $ay + $shiftY);
            $image->setImageFormat('png');
            $image->writeImage($output);
        } finally {
            @unlink($redrawnPath);
        }
    }

    /**
     * Where a redraw has the ground darker than the place by shadow, in a part that touches what was redrawn and
     * inside the crop that was redrawn: the shadow it gave them. Grey, white where it is.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     */
    private function shadowIn(Imagick $redrawn, Imagick $place, Imagick $matte, array $crop): Imagick
    {
        $shadow = app(PlaceComposite::class)->shadowsTakenOut($redrawn, $place, $matte);
        $inside = Masks::black($redrawn->getImageWidth(), $redrawn->getImageHeight());
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->rectangle($crop['x'], $crop['y'], $crop['x'] + $crop['width'] - 1, $crop['y'] + $crop['height'] - 1);
        $inside->drawImage($draw);
        $shadow->compositeImage($inside, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return $shadow;
    }

    /**
     * Hides the parts of a person put down at a spot that the place has in front of them, such as their legs
     * behind a counter: there the place is clearly nearer the camera than their feet.
     */
    private function hideBehind(Imagick $cutout, DepthGrid $depth, int $left, int $top, int $width, int $height, float $feetDepth): void
    {
        $limit = $feetDepth * (float) Config::get('pipeline.keyframes.move.in_front');
        $cover = array_map(fn(float $value) => $value < $limit ? 255 : 0, $depth->values);

        if (max($cover) === 0) {
            return;
        }

        $whole = new Imagick();
        $whole->newImage($depth->width, $depth->height, new ImagickPixel('black'));
        $whole->importImagePixels(0, 0, $depth->width, $depth->height, 'I', Imagick::PIXEL_CHAR, $cover);
        $whole->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);

        // The same area as the person, also where they stick out past the edge of the image.
        // The floor in front of the feet is nearer too; below the feet there is no person, so it hides nothing.
        $mask = new Imagick();
        $mask->newImage($cutout->getImageWidth(), $cutout->getImageHeight(), new ImagickPixel('black'));
        $mask->compositeImage($whole, Imagick::COMPOSITE_OVER, -$left, -$top);
        $mask->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_COPY);
        $cutout->compositeImage($mask, Imagick::COMPOSITE_DSTOUT, 0, 0);
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

        if ($this->topLayerMask !== null) {
            $mask = $this->maskAt(self::SMALL, $height);
            $pixels = $mask->exportImagePixels(0, 0, self::SMALL, $height, 'I', Imagick::PIXEL_CHAR);

            return [self::SMALL, $height, array_map(fn(int $value) => $value > 127, $pixels)];
        }

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
        $minimum = (int) ceil(self::MIN_AREA * $width * $height);
        $next = 0;

        foreach (Masks::parts($added, $width, $height) as $part) {
            if (count($part) < $minimum) {
                continue;
            }

            $next++;

            foreach ($part as $index) {
                $labels[$index] = $next;
            }
        }

        return $labels;
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
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool}  $shape
     */
    private function matte(Imagick $place, Imagick $render, array $shape): Imagick
    {
        ['width' => $smallWidth, 'height' => $smallHeight, 'shape' => $small] = $shape['small'];
        [$width, $height] = [$render->getImageWidth(), $render->getImageHeight()];

        // With the cut-out model's mask: that mask, only around this person, so others in the image stay.
        if ($this->topLayerMask !== null) {
            $around = new Imagick();
            $around->newImage($smallWidth, $smallHeight, new ImagickPixel('black'));
            $around->importImagePixels(0, 0, $smallWidth, $smallHeight, 'I', Imagick::PIXEL_CHAR, array_map(fn(bool $on) => $on ? 255 : 0, $this->dilate($small, $smallWidth, $smallHeight, 1)));
            $around->resizeImage($width, $height, Imagick::FILTER_POINT, 1);
            $matte = $this->maskAt($width, $height);
            $matte->compositeImage($around, Imagick::COMPOSITE_MULTIPLY, 0, 0);
            $matte->setImageColorspace(Imagick::COLORSPACE_GRAY);

            return $matte;
        }

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
     * Where the empty place goes back in when a shape is lifted: the shape itself, and every shadow around its old
     * feet or wheels, such as the one it was drawn with or the one an earlier move gave it. Neither is part of the shape, so
     * it would stay on the floor and stand still in the video while the person walks off. What else lies on top,
     * such as someone standing next to them, keeps its own shadow.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool}  $shape
     */
    /**
     * Where what was moved stood, with its shadow and a margin, but not where it stands now: the area a fill blends
     * into what is around it, so no patch of the place in another shade or a left shadow shows.
     */
    private function vacatedMask(Imagick $hole, Imagick $placed, int $left, int $top, int $width, int $height): string
    {
        $vacated = clone $hole;
        $vacated->setImageAlphaChannel(Imagick::ALPHACHANNEL_OFF);
        $vacated->thresholdImage(0.2 * Imagick::getQuantum());
        $vacated->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '6'));

        $there = Masks::black($width, $height);
        $alpha = clone $placed;
        $alpha->separateImageChannel(Imagick::CHANNEL_ALPHA);
        $there->compositeImage($alpha, Imagick::COMPOSITE_LIGHTEN, $left, $top);
        $there->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '3'));
        $there->negateImage(false);
        $vacated->compositeImage($there, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return Masks::png($vacated);
    }

    /**
     * The area the last move left behind, white, as a png; null before a move.
     */
    public function vacated(): ?string
    {
        return $this->vacated;
    }

    private function holeMask(Imagick $place, Imagick $image, array $shape, int $width, int $height): Imagick
    {
        $mask = $this->fillMask($shape, $width, $height);

        // Around the old feet or wheels, as far as a low sun throws a shadow, to either side; a thing in the air has none there, so nothing is found.

        [$bx, $by, $bw, $bh] = $this->pixels($shape['box'], $width, $height);
        $left = max(0, (int) round($bx - $bw * self::SHADOW_REACH));
        $right = min($width, (int) round($bx + $bw * (1 + self::SHADOW_REACH)));
        $top = max(0, (int) round($by + $bh * 0.55));
        $bottom = min($height, (int) round($by + $bh * 1.25));

        if ($right <= $left || $bottom <= $top) {
            return $mask;
        }

        [$w, $h] = [$right - $left, $bottom - $top];
        $imagePixels = $image->exportImagePixels($left, $top, $w, $h, 'RGB', Imagick::PIXEL_CHAR);
        $placePixels = $place->exportImagePixels($left, $top, $w, $h, 'RGB', Imagick::PIXEL_CHAR);
        $others = $this->topLayerMask !== null ? $this->othersOnTop($shape, $width, $height)->exportImagePixels($left, $top, $w, $h, 'I', Imagick::PIXEL_CHAR) : null;
        $shadow = [];

        for ($i = 0; $i < $w * $h; $i++) {
            $j = $i * 3;
            $shadow[] = ($others === null || $others[$i] < 128) && $this->isShadow($imagePixels[$j], $imagePixels[$j + 1], $imagePixels[$j + 2], $placePixels[$j], $placePixels[$j + 1], $placePixels[$j + 2]) ? 255 : 0;
        }

        $found = new Imagick();
        $found->newImage($w, $h, new ImagickPixel('black'));
        $found->importImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR, $shadow);
        // Its soft fringe, which is barely darker than the floor, goes too.
        $found->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '3'));
        $found->blurImage(0, 2);
        $found->setImageAlphaChannel(Imagick::ALPHACHANNEL_OFF);
        $mask->compositeImage($found, Imagick::COMPOSITE_LIGHTEN, $left, $top);
        $mask->setImageColorspace(Imagick::COLORSPACE_GRAY);

        return $mask;
    }

    /**
     * What else lies on top of the place than the shape, at full size.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool}  $shape
     */
    private function othersOnTop(array $shape, int $width, int $height): Imagick
    {
        $others = $this->maskAt($width, $height);
        $gone = $this->fillMask(['small' => $shape['small'], 'box' => $shape['box'], 'thing' => true], $width, $height);
        $gone->negateImage(false);
        $others->compositeImage($gone, Imagick::COMPOSITE_MULTIPLY, 0, 0);
        $others->thresholdImage(0.5 * Imagick::getQuantum());

        return $others;
    }

    /**
     * Where the empty place goes back in: the shape grown a few small pixels with a soft edge, so the whole shadow goes.
     *
     * @param  array{box: array{x: float, y: float, width: float, height: float}, small: array{width: int, height: int, shape: list<bool>}, thing?: bool}  $shape
     */
    private function fillMask(array $shape, int $width, int $height): Imagick
    {
        ['width' => $smallWidth, 'height' => $smallHeight, 'shape' => $small] = $shape['small'];
        $grown = $this->dilate($small, $smallWidth, $smallHeight, 3);

        $mask = new Imagick();
        $mask->newImage($smallWidth, $smallHeight, new ImagickPixel('black'));
        $mask->importImagePixels(0, 0, $smallWidth, $smallHeight, 'I', Imagick::PIXEL_CHAR, array_map(fn(bool $on) => $on ? 255 : 0, $grown));
        $mask->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);

        // The shadow the person cast on the floor goes too: an ellipse under their feet. A thing may fly or hang, with no floor under it.
        if (! ($shape['thing'] ?? false)) {
            [$x, $y, $w, $h] = $this->pixels($shape['box'], $width, $height);
            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel('white'));
            // Whole pixels: ImageMagick's drawing language reads a locale's decimal comma as an error.
            $draw->ellipse($x + intdiv($w, 2), $y + $h - intdiv($h, 40), max(4, intdiv($w * 7, 10)), max(3, intdiv($h, 22)), 0, 360);
            $mask->drawImage($draw);
        }

        // Drawing gives the mask transparency, which a copy of its opacity would read instead of its grey: off again.
        $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_OFF);
        $mask->blurImage(0, 3);
        $mask->setImageColorspace(Imagick::COLORSPACE_GRAY);

        return $mask;
    }

    /**
     * The top-layer mask, or the given one, at a size, grey.
     */
    private function maskAt(int $width, int $height, ?string $source = null): Imagick
    {
        return Masks::grey((string) ($source ?? $this->topLayerMask), $width, $height);
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
