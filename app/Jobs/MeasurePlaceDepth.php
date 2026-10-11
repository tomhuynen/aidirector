<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Images\PlaceDepth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Measures the depth of a chosen place in the background, so moving a person
 * in its keyframes is instant later.
 */
#[DeleteWhenMissingModels]
class MeasurePlaceDepth implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly Media $place)
    {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(PlaceDepth $depth): void
    {
        $depth->of($this->place);
    }
}
