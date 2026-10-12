<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Makes the chosen place without the named things it has, such as a landed
 * helicopter, in the background, so one can be moved by hand at once later.
 */
#[DeleteWhenMissingModels]
class EmptyPlace implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        public readonly Media $plate,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->plate->id;
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');

        if ($painter->composites() && $painter->emptiedPlate($shot, $this->plate) === null) {
            $painter->emptyPlate($shot, $this->plate);
        }

        $this->done();
    }

    public function failed(?Throwable $exception): void
    {
        // Its keyframes do not wait for ever: moving a thing of the place then asks to try again.
        $this->done();
        report($exception);
    }

    private function done(): void
    {
        $this->plate->refresh()->setCustomProperty(Shot::PLATE_EMPTIED_DONE, true)->save();
    }
}
