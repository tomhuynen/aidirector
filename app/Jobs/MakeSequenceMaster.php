<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\BlockingReader;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Shot;
use App\Support\Images\Cutout;
use App\Support\Images\Masks;
use App\Support\Images\PlaceDepth;
use App\Support\Images\PlaceLayout;
use App\Support\Images\PlaceObjects;
use App\Support\Images\TopLayerCutout;
use App\Support\Shots\SequencePlaces;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Once the master of a place of a sequence is chosen, with its cast where
 * they stand at the start: takes the people and what moves out of it for the
 * empty place every shot there is drawn on, reads where everyone stands, and
 * keeps the box around them, so each shot is cut from the same picture and
 * nobody switches sides. Then the shots that waited for the place are drawn.
 */
#[DeleteWhenMissingModels]
class MakeSequenceMaster implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly Shot $owner,
        public readonly string $place,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(SequencePlaces $places, KeyframePainter $painter, Cutout $cutout, PlaceObjects $objects, PlaceLayout $layout): void
    {
        $owner = $this->owner->load(['media', 'project']);
        $master = $places->master($owner, $this->place);
        $place = collect($places->places($owner))->firstWhere('key', $this->place);

        if ($master === null || $place === null || $places->chosen($owner, $this->place) !== null) {
            return;
        }

        $image = (string) Storage::disk($master->disk)->get($master->getPathRelativeToRoot());
        $cast = $owner->project->elements()->with('media')->get()->filter(fn(Element $element) => in_array($element->name, $place['elements'], true) && $element->type !== ElementType::PLACE);

        // The people, and the things that move in these shots, such as a truck: taken out of the empty place, found where they stand.
        $masks = [];

        try {
            $masks[] = $cutout->people($image);
        } catch (Throwable $exception) {
            report($exception);
        }

        $moving = $painter->movingAt($owner, $place);

        foreach ($cast->filter(fn(Element $element) => $element->type === ElementType::OBJECT && in_array(mb_strtolower($element->name), $moving, true)) as $thing) {
            if (($found = $objects->maskInDrawing($image, $thing)) !== null) {
                try {
                    $found = $cutout->refined($image, $found);
                } catch (Throwable $exception) {
                    report($exception);
                }

                $masks[] = $found;
            }
        }

        $taken = [...($cast->contains(fn(Element $element) => $element->type === ElementType::PERSON) ? ['people'] : []), ...$cast->filter(fn(Element $element) => in_array(mb_strtolower($element->name), $moving, true))->map(fn(Element $element) => "the {$element->name}")->values()->all()];
        $union = $masks === [] ? null : TopLayerCutout::keptSmall($masks[0], array_slice($masks, 1));
        $empty = $union !== null && $taken !== [] ? $painter->emptyOf($owner, $master, implode(' and ', $taken), $union) : $image;

        $chosen = $owner->addMediaFromString($empty)
            ->usingFileName('sequence-place-empty.png')
            ->withCustomProperties([
                'place' => $this->place,
                'master_id' => $master->id,
                SequencePlaces::BLOCKING => $this->blocking($master, $cast->pluck('name')->all()),
                SequencePlaces::CAST_BOX => $union !== null ? self::box($union) : null,
            ])
            ->toMediaCollection(Shot::SEQUENCE_PLACES);

        // Its depth, once, so every shot cut whole from it can move people at once; and its map, as for every chosen place of a sequence.
        app(PlaceDepth::class)->of($chosen);
        $map = $layout->of($chosen, $place['name'], $place['elements'])['map'] ?? '';

        if ($map !== '') {
            $chosen->setCustomProperty(SequencePlaces::MAP, $map)->save();
        }

        Shot::drawWaitingShots($owner->project_id);
    }

    /**
     * The master stands in for the empty place when it cannot be made, so the shots of the sequence do not wait for ever.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception);

        $master = app(SequencePlaces::class)->master($this->owner->load('media'), $this->place);

        if ($master !== null && app(SequencePlaces::class)->chosen($this->owner, $this->place) === null) {
            $master->copy($this->owner, Shot::SEQUENCE_PLACES)->setCustomProperty(SequencePlaces::MASTER, false)->save();
            Shot::drawWaitingShots($this->owner->project_id);
        }
    }

    /**
     * Where the cast stands in the master, in words; empty when it cannot be read.
     *
     * @param  list<string>  $cast
     */
    private function blocking(Media $master, array $cast): string
    {
        if ($cast === []) {
            return '';
        }

        $reader = new BlockingReader($cast);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $reader->prompt($reader->promptFor(), attachments: [ImageFile::fromStorage($master->getPathRelativeToRoot(), $master->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        } catch (Throwable $exception) {
            report($exception);

            return '';
        }

        return trim((string) ($response->toArray()['blocking'] ?? ''));
    }

    /**
     * The box around what a mask covers, as shares of the picture; null when it covers nothing.
     *
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public static function box(string $mask): ?array
    {
        $image = Masks::grey($mask);
        $image->thresholdImage(0.5 * Imagick::getQuantum());
        $trimmed = clone $image;
        $trimmed->trimImage(0);
        $page = $trimmed->getImagePage();
        [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];

        if ($trimmed->getImageWidth() >= $width && $trimmed->getImageHeight() >= $height && $image->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] < 1) {
            return null;
        }

        return ['x' => $page['x'] / $width, 'y' => $page['y'] / $height, 'width' => $trimmed->getImageWidth() / $width, 'height' => $trimmed->getImageHeight() / $height];
    }
}
