<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Draws one option of a place a sequence of shots plays in; the options of a
 * round are drawn side by side in one batch.
 */
#[DeleteWhenMissingModels]
class GenerateSequencePlaceOption implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $owner,
        public readonly string $place,
        public readonly int $variation,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter, SequencePlaces $places): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $owner = $this->owner->load('project');
        $place = collect($places->places($owner))->firstWhere('key', $this->place);

        if ($place !== null) {
            $painter->paintSequencePlaceOption($owner, $place, $this->variation);
        }
    }
}
