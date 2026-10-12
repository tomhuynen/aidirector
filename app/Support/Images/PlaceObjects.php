<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Ai\Agents\DetectionWordsWriter;
use App\Ai\Agents\ObjectInPlaceJudge;
use App\Models\Element;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Finds the objects of the cast and sets in an empty place: once per place
 * and object, while nobody stands in front of them, by common words an
 * object finder knows instead of the name made up for the project. What it
 * found is kept on the place, so every keyframe drawn on it uses the same.
 * First a vision model says whether the object is in the place at all: the
 * finder always finds something, also for a badge that is only carried in.
 */
class PlaceObjects
{
    /** On a place: the masks of the objects found in it, small, by object and words; false when not found. */
    public const MASKS = 'object_masks';

    /** On a place: by object id, whether the object is in the place at all. */
    public const PRESENT = 'objects_present';

    /** The width the masks are kept at; they are only used widened, so small is enough. */
    private const int MASK_WIDTH = 216;

    /** A mask covering more than this share of the place is not one object. */
    private const float MAX_SHARE = 0.35;

    /** In a drawing, something that moves can come close to the camera, such as a helicopter landing in front of it: only a mask covering more than this is surely not one thing. */
    private const float MAX_SHARE_IN_DRAWING = 0.8;

    public function __construct(private readonly Cutout $cutout) {}

    /**
     * Where the object is in the place, as a small mask that is scaled to the place where it is used, or null when it cannot be found.
     */
    public function maskIn(Media $place, Element $element): ?string
    {
        $words = $this->wordsFor($element);
        $key = $element->id . ':' . hash('xxh3', (string) json_encode($words));
        $masks = (array) $place->getCustomProperty(self::MASKS, []);

        if (! array_key_exists($key, $masks)) {
            $image = (string) Storage::disk($place->disk)->get($place->getPathRelativeToRoot());
            $found = $this->find($image, $words);
            $masks[$key] = $found === null ? false : base64_encode($this->shrink($found));
            $place->setCustomProperty(self::MASKS, $masks)->save();
        }

        return $masks[$key] === false ? null : base64_decode((string) $masks[$key]);
    }

    /**
     * Where the object is in a drawn keyframe, for one that is not in the
     * place, such as a helicopter that flies in or a badge put down on the
     * counter: it can only come from the drawing. Not kept, since every
     * drawing differs. Null when it cannot be found.
     */
    public function maskInDrawing(string $drawn, Element $element): ?string
    {
        return $this->find($drawn, $this->wordsFor($element), self::MAX_SHARE_IN_DRAWING);
    }

    /**
     * Whether the object is in the place at all, judged once by a vision model
     * and kept on the place. An object that is only carried in is not, and
     * comes along with the person who holds it. Without an answer it counts
     * as there, as before the judge existed.
     */
    public function isIn(Media $place, Element $element): bool
    {
        $seen = (array) $place->getCustomProperty(self::PRESENT, []);

        if (array_key_exists((string) $element->id, $seen)) {
            return (bool) $seen[(string) $element->id];
        }

        $reference = $element->reference();

        if ($reference === null) {
            return true;
        }

        $judge = new ObjectInPlaceJudge($element);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $judge->prompt($judge->promptFor(), attachments: [
                ImageFile::fromStorage($place->getPathRelativeToRoot(), $place->disk),
                ImageFile::fromStorage($reference->getPathRelativeToRoot(), $reference->disk),
            ], provider: 'openrouter', model: (string) Config::get('pipeline.models.keyframe_check'));
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }

        $present = (bool) ($response->toArray()['present'] ?? true);
        $place->setCustomProperty(self::PRESENT, [...$seen, (string) $element->id => $present])->save();

        return $present;
    }

    /**
     * The common words for an object, written once from its description; its name when they cannot be written.
     *
     * @return list<string>
     */
    public function wordsFor(Element $element): array
    {
        if (filled($element->detection_words)) {
            return array_values((array) $element->detection_words);
        }

        $writer = new DetectionWordsWriter($element);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $writer->prompt($writer->promptFor(), provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
            $words = array_values(array_filter(array_map(fn(mixed $word) => trim((string) $word), (array) ($response->toArray()['words'] ?? []))));
        } catch (Throwable $exception) {
            report($exception);

            return [$element->name];
        }

        if ($words === []) {
            return [$element->name];
        }

        $element->forceFill(['detection_words' => $words])->save();

        return $words;
    }

    /**
     * The first wording that finds something the size of one object.
     *
     * @param  list<string>  $words
     */
    private function find(string $image, array $words, float $maxShare = self::MAX_SHARE): ?string
    {
        foreach ($words as $word) {
            try {
                $mask = $this->cutout->thing($image, $word);
            } catch (Throwable $exception) {
                // The finder fails when nothing matches the words well enough, which is an answer, not an error.
                if (! Cutout::foundNothing($exception)) {
                    report($exception);
                }

                continue;
            }

            $share = $this->share($mask);

            if ($share > 0.0005 && $share < $maxShare) {
                return $mask;
            }
        }

        return null;
    }

    /**
     * The share of the image the mask covers.
     */
    private function share(string $mask): float
    {
        $image = new Imagick();
        $image->readImageBlob($mask);
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $image->thresholdImage(0.5 * Imagick::getQuantum());

        return (float) $image->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] / Imagick::getQuantum();
    }

    private function shrink(string $mask): string
    {
        return Masks::small(Masks::grey($mask), self::MASK_WIDTH);
    }
}
