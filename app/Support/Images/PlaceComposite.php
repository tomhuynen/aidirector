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
    /** A pixel differing more than this share from the place, near a person, is their shadow or something they touched. */
    private const float SHADOW_DIFFERENCE = 0.15;

    /** How far from a person, as a share of the image width, a shadow or a touched thing may lie. */
    private const float NEAR = 0.14;

    /**
     * A keyframe drawn on the place: the people, the things named in it and
     * the shadows the people cast, on top of the place.
     *
     * @param  string  $people  the mask of the people
     * @param  list<string>  $things  masks of the things named in the keyframe, which take their state from it, such as a phone off its hook
     */
    public function keyframe(string $place, string $drawn, string $people, array $things = []): string
    {
        [$placeImage, $drawnImage] = $this->pair($place, $drawn);
        $peopleMask = $this->read($people, $placeImage);
        $keep = $this->union([$peopleMask, ...array_map(fn(string $thing) => $this->read($thing, $placeImage), $things)]);
        $keep->compositeImage($this->shadows($placeImage, $drawnImage, $peopleMask), Imagick::COMPOSITE_LIGHTEN, 0, 0);

        // A little wider and closed, so hair, fingers and edges come along; soft, so no hard seam shows.
        $keep->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '2'));
        $keep->morphology(Imagick::MORPHOLOGY_CLOSE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '4'));
        $keep->gaussianBlurImage(0, 2);

        return $this->paste($placeImage, $drawnImage, $keep);
    }

    /**
     * A new state of the place, such as a door that is closed now: only the
     * changed thing comes from the edit, the rest from the place before.
     *
     * @param  list<string>  $masks  the changed thing, found in the place before and in the edit, so both its old and its new shape are covered
     */
    public function state(string $place, string $edited, array $masks): string
    {
        [$placeImage, $editedImage] = $this->pair($place, $edited);
        $keep = $this->union(array_map(fn(string $mask) => $this->read($mask, $placeImage), $masks));
        $keep->morphology(Imagick::MORPHOLOGY_CLOSE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '8'));
        $keep->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '3'));
        $keep->gaussianBlurImage(0, 1.5);

        return $this->paste($placeImage, $editedImage, $keep);
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
     * What differs clearly from the place close to the people, such as
     * their shadows on the floor and a cord they pull, with the light of the
     * drawn image matched to the place first, so a tone shift is no difference.
     */
    private function shadows(Imagick $place, Imagick $drawn, Imagick $people): Imagick
    {
        $matched = clone $drawn;
        $reference = clone $place;

        foreach ([Imagick::CHANNEL_RED, Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
            $from = $matched->getImageChannelMean($channel);
            $to = $reference->getImageChannelMean($channel);
            $scale = $to['standardDeviation'] / max(1.0, $from['standardDeviation']);
            $matched->functionImage(Imagick::FUNCTION_POLYNOMIAL, [$scale, ($to['mean'] - $from['mean'] * $scale) / Imagick::getQuantum()], $channel);
        }

        $matched->gaussianBlurImage(0, 2);
        $reference->gaussianBlurImage(0, 2);
        $matched->compositeImage($reference, Imagick::COMPOSITE_DIFFERENCE, 0, 0);

        // The largest difference in any channel: a red cord on a red wall differs in one channel only.
        $difference = clone $matched;
        $difference->separateImageChannel(Imagick::CHANNEL_RED);

        foreach ([Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
            $other = clone $matched;
            $other->separateImageChannel($channel);
            $difference->compositeImage($other, Imagick::COMPOSITE_LIGHTEN, 0, 0);
        }

        $difference->thresholdImage(self::SHADOW_DIFFERENCE * Imagick::getQuantum());
        $difference->morphology(Imagick::MORPHOLOGY_OPEN, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '1'));

        // Near the people only: grown on a small copy, which is quick.
        $near = clone $people;
        $small = 108;
        $near->resizeImage($small, (int) max(1, round($small * $people->getImageHeight() / $people->getImageWidth())), Imagick::FILTER_TRIANGLE, 1);
        $near->thresholdImage(0.1 * Imagick::getQuantum());
        $near->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, (string) max(1, (int) round(self::NEAR * $small))));
        $near->resizeImage($people->getImageWidth(), $people->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $near->thresholdImage(0.5 * Imagick::getQuantum());

        $difference->compositeImage($near, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return $difference;
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
