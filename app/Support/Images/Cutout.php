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

    /** What the cut-out model kept in front, per image, so people and things in one drawing share one call. @var array<string, string> */
    private array $fronts = [];

    /** What the people are called for the model that finds a thing by its name. */
    public const string PERSON = 'person';

    /** The width the parts of the cut-out are told apart at; finer is not needed to see which part holds what was found. */
    private const int PARTS_WIDTH = 216;

    /** How many times larger than what was found in it a part may be and still be it, such as a person with what they carry; larger is what they sit in or stand on. */
    private const int LARGER_THAN_FOUND = 3;

    /** How far around what was found, in pixels at the parts width, a larger part is still kept, such as a hand on a door or a cup someone holds. */
    private const int NEAR_FOUND = 3;

    /**
     * The people in the image: found by name, which also finds someone the
     * cut-out takes for background, such as someone half hidden behind a
     * counter, with the edges of the cut-out model. Of what the cut-out keeps,
     * only the parts with a person in them stay: it also takes a large object
     * in the foreground, such as a bin or a parked helicopter, which belongs
     * to the place. Nobody found is an empty mask; a finder that fails throws,
     * since the cut-out alone cannot tell a person from a helicopter.
     */
    public function people(string $image): string
    {
        try {
            $named = $this->thing($image, self::PERSON);
        } catch (Throwable $exception) {
            if (self::foundNothing($exception)) {
                return self::nobody($image);
            }

            throw $exception;
        }

        return $this->refined($image, $named);
    }

    /**
     * A thing found by name in a drawing, such as a helicopter flying in, with
     * the sharp edges of the cut-out model, the same way as the people: the
     * finder says what it is, the cut-out says where its edge runs. When the
     * cut-out has no part holding it, the finder's own mask stays.
     */
    public function refined(string $image, string $found): string
    {
        $front = new Imagick();
        $front->readImageBlob($this->inFront($image));
        $named = Masks::grey($found, $front->getImageWidth(), $front->getImageHeight());

        $mask = $this->partsHolding($front, $named);
        $mask->compositeImage($named, Imagick::COMPOSITE_LIGHTEN, 0, 0);
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

    /**
     * A mask without anyone, at the size of the image.
     */
    public static function nobody(string $image): string
    {
        return Masks::blackFor($image);
    }

    /**
     * Whether the finder failed because nothing in the image matched the words,
     * which it reports as an error instead of an empty mask.
     */
    public static function foundNothing(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'list index out of range');
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
     * What stands out in front, from the cut-out model's transparent result:
     * people, but also a large thing such as a bin or a helicopter; it only
     * gives the edges, never decides what is a person.
     */
    private function inFront(string $image): string
    {
        $key = hash('xxh3', $image);

        return $this->fronts[$key] ??= $this->cutOutFront($image);
    }

    private function cutOutFront(string $image): string
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
     * The parts of the cut-out that hold what was found by name, a person or
     * a thing, whole, with what a person carries; parts without it are left
     * out. A part much larger than what was found in it is something it sits
     * in or stands on, such as a helicopter a person sits in: of that, only
     * what was found and what is right around it stays, the rest belongs to
     * the place. When no part holds it, nothing of the cut-out stays: what
     * stands out in front is then something else.
     */
    private function partsHolding(Imagick $front, Imagick $named): Imagick
    {
        $width = self::PARTS_WIDTH;
        $height = (int) max(1, round($width * $front->getImageHeight() / $front->getImageWidth()));
        $small = fn(Imagick $mask): Imagick => tap(clone $mask, function (Imagick $copy) use ($width, $height) {
            $copy->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
            $copy->thresholdImage(0.5 * Imagick::getQuantum());
        });

        $kept = $small($front);
        $named = $small($named);
        $near = clone $named;
        $near->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, (string) self::NEAR_FOUND));

        $isFound = $named->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $isNear = $near->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $inside = array_map(fn(int $value) => $value > 127, $kept->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR));
        $pixels = array_fill(0, $width * $height, 0);
        $held = false;

        foreach (Masks::parts($inside, $width, $height) as $part) {
            $foundSize = count(array_filter($part, fn(int $index) => $isFound[$index] > 127));

            if ($foundSize === 0) {
                continue;
            }

            $held = true;
            $whole = count($part) <= self::LARGER_THAN_FOUND * $foundSize;

            foreach ($part as $index) {
                if ($whole || $isNear[$index] > 127) {
                    $pixels[$index] = 255;
                }
            }
        }

        if (! $held) {
            $nothing = clone $front;
            $nothing->evaluateImage(Imagick::EVALUATE_SET, 0);

            return $nothing;
        }

        $kept->importImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR, $pixels);

        // A little wider before scaling back up, so no edge of what was found is lost to the small size.
        $kept->morphology(Imagick::MORPHOLOGY_DILATE, 1, ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '2'));
        $kept->resizeImage($front->getImageWidth(), $front->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        $kept->thresholdImage(0.5 * Imagick::getQuantum());

        $mask = clone $front;
        $mask->compositeImage($kept, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        return $mask;
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
