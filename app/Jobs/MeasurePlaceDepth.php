<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Images\PlaceDepth;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Measures the depth of a chosen place in the background, so moving a person
 * in its keyframes is instant later.
 */
#[DeleteWhenMissingModels]
class MeasurePlaceDepth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly Media $place)
    {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->place->id;
    }

    public function handle(PlaceDepth $depth): void
    {
        $depth->of($this->place);
        $this->tried();
    }

    public function failed(?Throwable $exception): void
    {
        // Its keyframes do not wait for ever: moving then sizes without depth.
        $this->tried();
        report($exception);
    }

    private function tried(): void
    {
        $this->place->refresh()->setCustomProperty(PlaceDepth::TRIED, true)->save();
    }
}
