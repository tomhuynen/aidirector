<?php

declare(strict_types=1);

namespace App\Ai\Bench;

use Laravel\Ai\Files\Image;

/**
 * What one variant made of one case, and what the judges get to score it.
 */
final readonly class BenchAnswer
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $usage  token counts, or the `cost` the provider reported
     * @param  list<Image>  $images  the images the judges see
     * @param  array<string, float>  $measures  numbers measured in code, averaged per variant
     */
    public function __construct(
        public string $instructions,
        public string $prompt,
        public array $output,
        public array $usage,
        public array $images = [],
        public array $measures = [],
    ) {}
}
