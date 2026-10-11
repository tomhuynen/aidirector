<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\PlaceMapper;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Writes the map of a place of a sequence once it is chosen: where its fixed
 * things stand, for every shot that shows another part of it.
 */
#[DeleteWhenMissingModels]
class MapSequencePlace implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly Shot $owner,
        public readonly string $place,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(SequencePlaces $places): void
    {
        $owner = $this->owner->load('media');
        $chosen = $places->chosen($owner, $this->place);
        $place = collect($places->places($owner))->firstWhere('key', $this->place);

        if ($chosen === null || $place === null) {
            return;
        }

        $mapper = new PlaceMapper($place['name'], $place['elements']);

        /** @var StructuredAgentResponse $response */
        $response = $mapper->prompt($mapper->promptFor(), attachments: [ImageFile::fromStorage($chosen->getPathRelativeToRoot(), $chosen->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        $map = trim((string) ($response->toArray()['map'] ?? ''));

        if ($map !== '') {
            $chosen->setCustomProperty(SequencePlaces::MAP, $map)->save();
        }
    }
}
