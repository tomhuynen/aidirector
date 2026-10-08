<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Config;
use Imagick;
use RuntimeException;

/**
 * Finds what to keep of an image as a mask, white on black, at the size of
 * the image: the people, or a thing by its name.
 */
class Cutout
{
    public function __construct(private readonly ReplicateClient $replicate) {}

    /**
     * The people in the image, from the cut-out model's transparent result.
     */
    public function people(string $image): string
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
