<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Element;
use Laravel\Ai\Files\StoredImage;

/**
 * The images a keyframe render is anchored to, in the order they are
 * attached: the style sheet, the reference images of the cast and sets in
 * the keyframe, keyframe 1 and the keyframe directly before. The brief
 * describes the same list, so the text always matches what is sent.
 */
final class KeyframeReferences
{
    /**
     * @param  list<Element>  $elements  every element in the keyframe, described in the prompt
     * @param  list<array{element: Element, image: StoredImage}>  $elementImages  the ones attached as images
     */
    public function __construct(
        public readonly ?StoredImage $style = null,
        public readonly array $elements = [],
        public readonly array $elementImages = [],
        public readonly ?StoredImage $first = null,
        public readonly ?StoredImage $previous = null,
    ) {}

    /**
     * @return list<StoredImage>
     */
    public function images(): array
    {
        return array_values(array_filter([
            $this->style,
            ...array_column($this->elementImages, 'image'),
            $this->first,
            $this->previous,
        ]));
    }
}
