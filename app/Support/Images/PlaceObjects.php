<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Ai\Agents\DetectionWordsWriter;
use App\Models\Element;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Finds the objects of the cast and sets in an empty place: once per place
 * and object, while nobody stands in front of them, by common words an
 * object finder knows instead of the name made up for the project. What it
 * found is kept on the place, so every keyframe drawn on it uses the same.
 */
class PlaceObjects
{
    /** On a place: the masks of the objects found in it, small, by object and words; false when not found. */
    public const MASKS = 'object_masks';

    /** The width the masks are kept at; they are only used widened, so small is enough. */
    private const int MASK_WIDTH = 216;

    /** A mask covering more than this share of the place is not one object. */
    private const float MAX_SHARE = 0.35;

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
    private function find(string $image, array $words): ?string
    {
        foreach ($words as $word) {
            try {
                $mask = $this->cutout->thing($image, $word);
            } catch (Throwable $exception) {
                // The finder fails when nothing matches the words well enough.
                report($exception);

                continue;
            }

            $share = $this->share($mask);

            if ($share > 0.0005 && $share < self::MAX_SHARE) {
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
        $image = new Imagick();
        $image->readImageBlob($mask);
        $image->resizeImage(self::MASK_WIDTH, (int) max(1, round(self::MASK_WIDTH * $image->getImageHeight() / $image->getImageWidth())), Imagick::FILTER_TRIANGLE, 1);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }
}
