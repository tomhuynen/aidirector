<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Config;
use Imagick;
use ImagickKernel;
use RuntimeException;
use Throwable;

/**
 * Finds what to keep of an image as a mask, white on black, at the size of
 * the image: the people, or a thing by its name.
 */
class Cutout
{
    public function __construct(private readonly ReplicateClient $replicate) {}

    /** What the people are called for the model that finds a thing by its name. */
    public const string PERSON = 'person';

    /** The width the parts of the cut-out are told apart at; finer is not needed to see which part holds a person. */
    private const int PARTS_WIDTH = 216;

    /** The most steps a person is grown over their part of the cut-out; more than enough to fill the tallest figure at that width. */
    private const int MAX_GROW_STEPS = 600;

    /**
     * The people in the image: the cut-out model keeps the people in front,
     * and finding them by name adds anyone it takes for background, such as
     * someone half hidden behind a counter. Of what the cut-out keeps, only
     * the parts with a person in them stay: it also takes a large object in
     * the foreground, such as a bin, which belongs to the place.
     */
    public function people(string $image): string
    {
        $mask = new Imagick();
        $mask->readImageBlob($this->inFront($image));

        try {
            $named = new Imagick();
            $named->readImageBlob($this->thing($image, self::PERSON));
            $mask = $this->withPeople($mask, $named);
            $mask->compositeImage($named, Imagick::COMPOSITE_LIGHTEN, 0, 0);
        } catch (Throwable $exception) {
            report($exception);
        }

        $mask->setImageFormat('png');

        return $mask->getImageBlob();
    }

    /**
     * The thing the name describes, such as "door" or "wall telephone".
     */
    public function thing(string $image, string $name): string
    {
        $output = $this->replicate->run((string) Config::get('pipeline.keyframes.composite.object_model'), ['image' => ReplicateClient::dataUrl($image), 'text_prompt' => $name]);
        $mask = new Imagick();
        $mask->readImageBlob($this->replicate->download($this->url($output)));

        return $this->mask($mask, $image);
    }

    private function url(mixed $output): string
    {
        $url = is_array($output) ? end($output) : $output;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The cut-out model returned no image.');
        }

        return $url;
    }

    /**
     * The people in front, from the cut-out model's transparent result.
     */
    private function inFront(string $image): string
    {
        $output = $this->replicate->run((string) Config::get('pipeline.keyframes.composite.people_model'), ['image' => ReplicateClient::dataUrl($image)]);
        $cut = new Imagick();
        $cut->readImageBlob($this->replicate->download($this->url($output)));

        if (! $cut->getImageAlphaChannel()) {
            throw new RuntimeException('The cut-out model returned an image without transparency.');
        }

        // The transparency is the mask: opaque is a person.
        $cut->setImageAlphaChannel(Imagick::ALPHACHANNEL_EXTRACT);

        return $this->mask($cut, $image);
    }

    /**
     * The parts of the cut-out that hold a person found by name, whole, with
     * what they carry; parts without anyone in them are left out. Found by
     * growing the people over the cut-out until they fill their parts. When
     * nobody is found by name, the whole cut-out stays.
     */
    private function withPeople(Imagick $front, Imagick $named): Imagick
    {
        $height = (int) max(1, round(self::PARTS_WIDTH * $front->getImageHeight() / $front->getImageWidth()));
        $small = fn(Imagick $mask): Imagick => tap(clone $mask, function (Imagick $copy) use ($height) {
            $copy->resizeImage(self::PARTS_WIDTH, $height, Imagick::FILTER_TRIANGLE, 1);
            $copy->thresholdImage(0.5 * Imagick::getQuantum());
        });

        $parts = $small($front);
        $grown = $small($named);
        $grown->compositeImage($parts, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        if ($grown->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] <= 0) {
            return $front;
        }

        $step = ImagickKernel::fromBuiltIn(Imagick::KERNEL_SQUARE, '1');

        for ($i = 0, $before = -1.0; $i < self::MAX_GROW_STEPS; $i++) {
            $grown->morphology(Imagick::MORPHOLOGY_DILATE, 1, $step);
            $grown->compositeImage($parts, Imagick::COMPOSITE_MULTIPLY, 0, 0);
            $now = (float) $grown->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'];

            if ($now === $before) {
                break;
            }

            $before = $now;
        }

        // A little wider before scaling back up, so no edge of a person is lost to the small size.
        $grown->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '2'));
        $grown->resizeImage($front->getImageWidth(), $front->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $grown->thresholdImage(0.5 * Imagick::getQuantum());

        $kept = clone $front;
        $kept->compositeImage($grown, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return $kept;
    }

    /**
     * Grey, at the size of the image the mask is for.
     */
    private function mask(Imagick $mask, string $image): string
    {
        $size = new Imagick();
        $size->pingImageBlob($image);
        $mask->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $mask->resizeImage($size->getImageWidth(), $size->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $mask->setImageFormat('png');

        return $mask->getImageBlob();
    }
}
