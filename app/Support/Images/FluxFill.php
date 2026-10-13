<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Config;
use Imagick;
use RuntimeException;

/**
 * FLUX Fill on Replicate: draws only inside the white of a mask and fits what
 * it draws to what is around it; everything outside the mask stays as it is.
 * The result comes back at the size of the image it was given.
 */
class FluxFill
{
    public function __construct(private readonly ReplicateClient $replicate) {}

    public function fill(string $image, string $mask, string $prompt): string
    {
        $output = $this->replicate->runModel((string) Config::get('pipeline.keyframes.staging.fill_model'), [
            'image' => ReplicateClient::dataUrl($image),
            'mask' => ReplicateClient::dataUrl($mask),
            'prompt' => $prompt,
            'output_format' => 'png',
        ]);
        $url = is_array($output) ? ($output[0] ?? null) : $output;

        if (! is_string($url)) {
            throw new RuntimeException('FLUX Fill returned no image.');
        }

        $size = new Imagick();
        $size->pingImageBlob($image);
        $filled = new Imagick();
        $filled->readImageBlob($this->replicate->download($url));
        $filled->resizeImage($size->getImageWidth(), $size->getImageHeight(), Imagick::FILTER_LANCZOS, 1);
        $filled->setImageFormat('png');

        return $filled->getImageBlob();
    }
}
