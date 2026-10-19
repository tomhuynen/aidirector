<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Element;
use Laravel\Ai\Files\StoredImage;

/**
 * The images a keyframe render is anchored to, in the order they are
 * attached. The start frame is drawn from the style sheet and the pictures
 * of its cast and sets. The end frame is drawn on top of the start frame,
 * which comes first and keeps the place and the camera still; then the
 * pictures of the people and objects. The brief describes the same list, so
 * the text matches what is sent.
 */
final class KeyframeReferences
{
    /**
     * @param  list<Element>  $elements  every element in the keyframe, described in the prompt
     * @param  list<array{element: Element, image: StoredImage}>  $elementImages  the ones attached as images
     * @param  StoredImage|null  $first  the start frame, which the end frame is drawn on
     * @param  bool  $firstShowsCast  false when people in this keyframe are not in the start frame, such as someone who walks in
     * @param  list<string>  $castNames  the people the start frame does not show, who are added to it
     * @param  StoredImage|null  $setting  a keyframe or the place of another shot this one plays in, for the start frame
     * @param  string  $settingLabel  what that image is, such as "the end frame of SH100, the shot before"
     * @param  bool  $settingIsMoment  the setting is a keyframe of that shot, an instant to continue, not only its place
     * @param  StoredImage|null  $view  an earlier frame from the camera this shot returns to, which the start frame is drawn on
     * @param  string  $viewLabel  what that frame is, such as "the end frame of SH110"
     * @param  StoredImage|null  $master  the master plate of the sequence: the reference for the place, never copied from a copy
     * @param  string  $masterLabel  what that frame is, such as "the start frame of SH110, the first shot of this sequence"
     * @param  StoredImage|null  $place  the picture of the shot's place, for how it looks; the start frame is drawn in it with its own camera
     */
    public function __construct(
        public readonly ?StoredImage $style = null,
        public readonly array $elements = [],
        public readonly array $elementImages = [],
        public readonly ?StoredImage $first = null,
        public readonly bool $firstShowsCast = true,
        public readonly array $castNames = [],
        public readonly ?StoredImage $setting = null,
        public readonly string $settingLabel = '',
        public readonly bool $settingIsMoment = false,
        public readonly ?StoredImage $place = null,
        public readonly ?StoredImage $view = null,
        public readonly string $viewLabel = '',
        public readonly ?StoredImage $master = null,
        public readonly string $masterLabel = '',
    ) {}

    /**
     * What each attached image is, in the same order as {@see images()}, so a version can show what it was drawn from.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_values(array_filter([
            $this->first !== null ? 'The start frame, the image that is edited' : null,
            $this->view !== null ? "The same camera: {$this->viewLabel}, the image that is edited" : null,
            $this->style !== null ? 'Style sheet' : null,
            $this->place !== null ? 'The place' : null,
            $this->master !== null ? "The master plate: {$this->masterLabel}" : null,
            $this->setting !== null ? 'The setting: ' . $this->settingLabel : null,
            ...array_map(fn(array $entry) => "Picture of {$entry['element']->name}", $this->elementImages),
        ]));
    }

    /**
     * @return list<StoredImage>
     */
    public function images(): array
    {
        return array_values(array_filter([
            $this->first,
            $this->view,
            $this->style,
            $this->place,
            $this->master,
            $this->setting,
            ...array_column($this->elementImages, 'image'),
        ]));
    }
}
