<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Element;
use Laravel\Ai\Files\StoredImage;

/**
 * The images a keyframe render is anchored to, in the order they are
 * attached. Keyframe 1 is drawn from the style sheet and the pictures of its
 * cast and sets. Every later keyframe is drawn on top of keyframe 1, which
 * comes first and keeps the place and the camera still; then the pictures of
 * the people and objects, and the keyframe directly before for the state of
 * things. The brief describes the same list, so the text matches what is sent.
 */
final class KeyframeReferences
{
    /**
     * @param  list<Element>  $elements  every element in the keyframe, described in the prompt
     * @param  list<array{element: Element, image: StoredImage}>  $elementImages  the ones attached as images
     * @param  bool  $firstShowsCast  false when people in this keyframe are not in keyframe 1, such as someone who walks in later
     * @param  list<string>  $castNames  the people keyframe 1 does not show, who are added to it
     * @param  bool  $firstIsPlate  `$first` is the place without people, made from keyframe 1
     */
    public function __construct(
        public readonly ?StoredImage $style = null,
        public readonly array $elements = [],
        public readonly array $elementImages = [],
        public readonly ?StoredImage $first = null,
        public readonly ?StoredImage $previous = null,
        public readonly bool $firstShowsCast = true,
        public readonly array $castNames = [],
        public readonly bool $firstIsPlate = false,
    ) {}

    /**
     * What each attached image is, in the same order as {@see images()}, so a version can show what it was drawn from.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_values(array_filter([
            $this->first !== null ? ($this->firstIsPlate ? 'The place without people, the image that is edited' : 'Keyframe 1, the image that is edited') : null,
            $this->style !== null ? 'Style sheet' : null,
            ...array_map(fn(array $entry) => "Picture of {$entry['element']->name}", $this->elementImages),
            $this->previous !== null ? 'The keyframe before' : null,
        ]));
    }

    /**
     * @return list<StoredImage>
     */
    public function images(): array
    {
        return array_values(array_filter([
            $this->first,
            $this->style,
            ...array_column($this->elementImages, 'image'),
            $this->previous,
        ]));
    }
}
