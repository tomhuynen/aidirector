<?php

declare(strict_types=1);

namespace App\Support\Images;

use Imagick;
use ImagickKernel;

/**
 * Puts what changed onto the place itself, so the place stays the same
 * picture in every keyframe: the image model redraws the whole image with
 * every edit, and the background shifts a little each time. Only what a
 * mask marks comes from the drawn image, with a soft edge; the rest is the
 * place, pixel for pixel.
 */
class PlaceComposite
{
    /** A pixel this share darker than the place, below a person, is their shadow. */
    private const float SHADOW_DARKER = 0.08;

    /** The radius in pixels of the smallest dark area that counts as a shadow. */
    private const int SHADOW_MIN_SIZE = 4;

    /** How far below a person a shadow may reach, as a share of the image height. */
    private const float SHADOW_DEPTH = 0.04;

    /** How far sideways a shadow may reach at that depth, as a share of the image width. */
    private const float SHADOW_REACH = 0.07;

    /** How far below the soles the contact shadow lies, as a share of the image height. */
    private const float CONTACT_DEPTH = 0.01;

    /** How far sideways of the soles the contact shadow reaches at that depth, as a share of the image width. */
    private const float CONTACT_REACH = 0.008;

    /** How much darker the place gets right under a sole the drawing gave no shadow, from 0 to 1. */
    private const float CONTACT_SHADE = 0.22;

    /** How far to each side, as a share of the image width, the lowest point of a column is compared with, to tell feet from an arm. */
    private const float FEET_WINDOW = 0.06;

    /** How much higher than the lowest point close by, as a share of the image height, a point still counts as a foot. */
    private const float FEET_TOLERANCE = 0.03;

    /** How much wider than an object in the place its area is taken from the drawing, as a share of the image width, so its new shape fits too. */
    private const float AREA_MARGIN = 0.025;

    /** How close to an object, as a share of the image width, a person counts as touching it, such as by a cord or a hand just in front of it. */
    private const float TOUCH_MARGIN = 0.02;

    /**
     * A keyframe drawn on the place: the people, the objects they touch and
     * the shadows the people cast, on top of the place.
     *
     * @param  string  $people  the mask of the people
     * @param  list<string>  $areas  where the objects the people touch are in the place; there they take the drawing, a little wider, so both their old and new shape are covered, such as a phone off its hook
     */
    public function keyframe(string $place, string $drawn, string $people, array $areas = []): string
    {
        [$placeImage, $drawnImage] = $this->pair($place, $drawn);
        $peopleMask = $this->read($people, $placeImage);
        $margin = (string) max(1, (int) round(self::AREA_MARGIN * $placeImage->getImageWidth()));
        $keep = $this->union([$peopleMask, ...array_map(function (string $area) use ($placeImage, $margin) {
            $mask = $this->read($area, $placeImage);
            $mask->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, $margin));

            return $mask;
        }, $areas)]);
        [$shadows, $dark] = $this->shadows($placeImage, $drawnImage, $peopleMask);
        $keep->compositeImage($shadows, Imagick::COMPOSITE_LIGHTEN, 0, 0);

        // Where the drawing has no shadow right under a sole, the place gets a soft one, so nobody floats.
        $this->shadeUnderFeet($placeImage, $peopleMask, $dark);

        // A little wider and closed, so hair, fingers and edges come along; soft, so no hard seam shows.
        $keep->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '2'));
        $keep->morphology(Imagick::MORPHOLOGY_CLOSE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '4'));
        $keep->gaussianBlurImage(0, 2);

        return $this->paste($placeImage, $drawnImage, $keep);
    }

    /**
     * Whether the people touch the object, or reach right up to it.
     *
     * @param  string  $area  where the object is in the place
     */
    public function touches(string $area, string $people): bool
    {
        $peopleMask = new Imagick();
        $peopleMask->readImageBlob($people);
        $width = 216;
        $height = (int) max(1, round($width * $peopleMask->getImageHeight() / $peopleMask->getImageWidth()));

        $object = $this->read($area, $this->blank($width, $height));
        $object->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, (string) max(1, (int) round(self::TOUCH_MARGIN * $width))));
        $object->compositeImage($this->read($people, $object), Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return $object->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] > 0;
    }

    /**
     * A new state of the place, such as a door that is closed now: only the
     * changed thing comes from the edit, the rest from the place before.
     *
     * @param  list<string>  $masks  the changed thing, found in the place before and in the edit, so both its old and its new shape are covered;
     *                               of everything the words found, such as two doors, only the part that changed is taken
     */
    public function state(string $place, string $edited, array $masks): string
    {
        [$placeImage, $editedImage] = $this->pair($place, $edited);
        $keep = $this->changedParts($placeImage, $editedImage, $this->union(array_map(fn(string $mask) => $this->read($mask, $placeImage), $masks)));
        $keep->morphology(Imagick::MORPHOLOGY_CLOSE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '8'));
        $keep->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '3'));
        $keep->gaussianBlurImage(0, 1.5);

        return $this->paste($placeImage, $editedImage, $keep);
    }

    /**
     * Of the separate parts of a mask, only the one that changed: the words
     * for a change, such as "door", can also find another door further back,
     * which the edit only redrew and must stay the place's own. Measured on
     * small copies, with the light of the edit matched first.
     */
    private function changedParts(Imagick $place, Imagick $edited, Imagick $mask): Imagick
    {
        $width = 216;
        $height = (int) max(1, round($width * $place->getImageHeight() / $place->getImageWidth()));
        [$before, $after, $small] = [clone $place, clone $edited, clone $mask];

        foreach ([$before, $after, $small] as $image) {
            $image->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        }

        foreach ([Imagick::CHANNEL_RED, Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
            $from = $after->getImageChannelMean($channel);
            $to = $before->getImageChannelMean($channel);
            $scale = $to['standardDeviation'] / max(1.0, $from['standardDeviation']);
            $after->functionImage(Imagick::FUNCTION_POLYNOMIAL, [$scale, ($to['mean'] - $from['mean'] * $scale) / Imagick::getQuantum()], $channel);
        }

        $before->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $after->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $before->compositeImage($after, Imagick::COMPOSITE_DIFFERENCE, 0, 0);

        $inside = $small->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $difference = $before->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $parts = $this->parts(array_map(fn(int $value) => $value > 127, $inside), $width, $height);

        // A change in the plan is about one thing: the part with the most change in total. A redrawn door further back changes
        // about as much per pixel, but it is smaller.
        $change = array_map(fn(array $pixels) => array_sum(array_map(fn(int $index) => $difference[$index], $pixels)), $parts);
        $kept = array_fill(0, $width * $height, 0);

        if ($change !== []) {
            foreach ($parts[array_keys($change, max($change))[0]] as $pixel) {
                $kept[$pixel] = 255;
            }
        }

        $small->importImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR, $kept);
        $small->resizeImage($mask->getImageWidth(), $mask->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $small->thresholdImage(0.5 * Imagick::getQuantum());

        return $small;
    }

    /**
     * The separate parts of a mask, each as the indexes of its pixels.
     *
     * @param  list<bool>  $inside
     * @return list<list<int>>
     */
    private function parts(array $inside, int $width, int $height): array
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

    /**
     * The place and the drawn image, at the size of the place.
     *
     * @return array{0: Imagick, 1: Imagick}
     */
    private function pair(string $place, string $drawn): array
    {
        $placeImage = new Imagick();
        $placeImage->readImageBlob($place);
        $placeImage->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $drawnImage = new Imagick();
        $drawnImage->readImageBlob($drawn);
        $drawnImage->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

        if ($drawnImage->getImageWidth() !== $placeImage->getImageWidth() || $drawnImage->getImageHeight() !== $placeImage->getImageHeight()) {
            $drawnImage->resizeImage($placeImage->getImageWidth(), $placeImage->getImageHeight(), Imagick::FILTER_LANCZOS, 1);
        }

        return [$placeImage, $drawnImage];
    }

    /**
     * A mask as black and white at the size of the place.
     */
    private function read(string $mask, Imagick $place): Imagick
    {
        $image = new Imagick();
        $image->readImageBlob($mask);
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $image->resizeImage($place->getImageWidth(), $place->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $image->thresholdImage(0.5 * Imagick::getQuantum());

        return $image;
    }

    private function blank(int $width, int $height): Imagick
    {
        $image = new Imagick();
        $image->newImage($width, $height, 'black');

        return $image;
    }

    /**
     * @param  non-empty-list<Imagick>  $masks
     */
    private function union(array $masks): Imagick
    {
        $union = clone $masks[0];

        foreach (array_slice($masks, 1) as $mask) {
            $union->compositeImage($mask, Imagick::COMPOSITE_LIGHTEN, 0, 0);
        }

        return $union;
    }

    /**
     * The shadows the people cast on the floor: darker than the place and
     * below the feet. Right under the soles every darker pixel counts, so the
     * contact shadow stays attached to the shoe; further away only areas wide
     * enough to be a shadow, in a cone that widens downward, so a thin wedge
     * along the base of a wall or pillar, or a redrawn edge beside a person,
     * never comes along. What the people hold or touch comes in through the
     * objects instead. Returns those shadows and everything darker than the place.
     *
     * @return array{0: Imagick, 1: Imagick}
     */
    private function shadows(Imagick $place, Imagick $drawn, Imagick $people): array
    {
        $matched = clone $drawn;
        $reference = clone $place;

        // The light of the drawn image matched to the place first, so a tone shift is no shadow.
        foreach ([Imagick::CHANNEL_RED, Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
            $from = $matched->getImageChannelMean($channel);
            $to = $reference->getImageChannelMean($channel);
            $scale = $to['standardDeviation'] / max(1.0, $from['standardDeviation']);
            $matched->functionImage(Imagick::FUNCTION_POLYNOMIAL, [$scale, ($to['mean'] - $from['mean'] * $scale) / Imagick::getQuantum()], $channel);
        }

        foreach ([$matched, $reference] as $image) {
            $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $image->gaussianBlurImage(0, 2);
        }

        // How much darker the drawn image is than the place.
        $reference->compositeImage($matched, Imagick::COMPOSITE_MINUSSRC, 0, 0);
        $reference->thresholdImage(self::SHADOW_DARKER * Imagick::getQuantum());
        $dark = clone $reference;

        // Further away: only areas wide enough to be a shadow.
        $reference->morphology(Imagick::MORPHOLOGY_OPEN, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, (string) self::SHADOW_MIN_SIZE));
        $reference->compositeImage($this->belowFeet($people, self::SHADOW_DEPTH, self::SHADOW_REACH), Imagick::COMPOSITE_MULTIPLY, 0, 0);

        // Right under the soles: every darker pixel.
        $contact = clone $dark;
        $contact->compositeImage($this->belowFeet($people, self::CONTACT_DEPTH, self::CONTACT_REACH), Imagick::COMPOSITE_MULTIPLY, 0, 0);
        $reference->compositeImage($contact, Imagick::COMPOSITE_LIGHTEN, 0, 0);

        return [$reference, $dark];
    }

    /**
     * Darkens the place softly right under the soles where the drawing has
     * no shadow there, like the contact shadow of a person moved by hand.
     */
    private function shadeUnderFeet(Imagick $place, Imagick $people, Imagick $dark): void
    {
        $shade = $this->belowFeet($people, self::CONTACT_DEPTH, self::CONTACT_REACH);
        $missing = clone $dark;
        $missing->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '3'));
        $missing->negateImage(false);
        $outside = clone $people;
        $outside->negateImage(false);
        $shade->compositeImage($missing, Imagick::COMPOSITE_MULTIPLY, 0, 0);
        $shade->compositeImage($outside, Imagick::COMPOSITE_MULTIPLY, 0, 0);
        $shade->gaussianBlurImage(0, 5);

        // White where nothing changes, a little darker where the shade is.
        $shade->evaluateImage(Imagick::EVALUATE_MULTIPLY, self::CONTACT_SHADE);
        $shade->negateImage(false);
        $shade->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $place->compositeImage($shade, Imagick::COMPOSITE_MULTIPLY, 0, 0);
    }

    /**
     * The floor below the people: from the lowest point of the people in
     * each column a cone spreads downward, narrow at the feet and wider
     * further down, worked out on a small copy, which is quick.
     */
    private function belowFeet(Imagick $people, float $depthShare, float $reachShare): Imagick
    {
        $width = $people->getImageWidth();
        $height = $people->getImageHeight();
        $small = clone $people;
        $smallWidth = 144;
        $smallHeight = (int) max(1, round($smallWidth * $height / $width));
        $small->resizeImage($smallWidth, $smallHeight, Imagick::FILTER_TRIANGLE, 1);

        // The lowest person pixel of each column, so the cone does not widen beside the body, where the walls are.
        $pixels = $small->exportImagePixels(0, 0, $smallWidth, $smallHeight, 'I', Imagick::PIXEL_CHAR);
        $lowest = array_fill(0, $smallWidth, -1);

        for ($x = 0; $x < $smallWidth; $x++) {
            for ($y = $smallHeight - 1; $y >= 0; $y--) {
                if ($pixels[$y * $smallWidth + $x] > 50) {
                    $lowest[$x] = $y;

                    break;
                }
            }
        }

        // Only feet: a lowest point well above the lowest one close by is an arm or a hand beside the legs.
        $window = (int) max(1, round(self::FEET_WINDOW * $smallWidth));
        $tolerance = self::FEET_TOLERANCE * $smallHeight;
        $feet = array_fill(0, $smallWidth * $smallHeight, 0);

        foreach ($lowest as $x => $y) {
            if ($y >= 0 && $y >= max(array_slice($lowest, max(0, $x - $window), 2 * $window + 1)) - $tolerance) {
                $feet[$y * $smallWidth + $x] = 255;
            }
        }

        $small->importImagePixels(0, 0, $smallWidth, $smallHeight, 'I', Imagick::PIXEL_CHAR, $feet);

        // Rows below the origin, each a little wider than the one above it.
        $depth = (int) max(2, round($depthShare * $smallHeight));
        $reach = (int) max(1, round($reachShare * $smallWidth));
        $matrix = [];

        for ($row = 0; $row < 2 * $depth + 1; $row++) {
            $below = $row - $depth;
            $spread = $below >= 0 ? (int) round(1 + ($reach - 1) * $below / $depth) : -1;
            $matrix[] = array_map(fn(int $column) => abs($column - $reach) <= $spread ? 1.0 : 0.0, range(0, 2 * $reach));
        }

        // The cone lies below the origin, so each person pixel spreads downward.
        $small->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromMatrix($matrix, [$reach, $depth]));
        $small->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        $small->thresholdImage(0.5 * Imagick::getQuantum());

        return $small;
    }

    /**
     * The drawn image where the mask is white, the place elsewhere.
     */
    private function paste(Imagick $place, Imagick $drawn, Imagick $mask): string
    {
        $drawn->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $drawn->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $place->compositeImage($drawn, Imagick::COMPOSITE_OVER, 0, 0);
        $place->setImageFormat('png');

        return $place->getImageBlob();
    }
}
