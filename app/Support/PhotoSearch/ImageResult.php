<?php

declare(strict_types=1);

namespace App\Support\PhotoSearch;

/**
 * One image from a search, as the provider returns it.
 */
final readonly class ImageResult
{
    public function __construct(
        public string $imageUrl,
        public string $thumbnailUrl,
        public ?string $sourceUrl,
        public ?string $title,
        public ?string $domain,
        public ?int $width,
        public ?int $height,
    ) {}
}
