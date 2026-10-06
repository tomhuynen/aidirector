<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Models\Shot;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Draws one empty place for the director to start the shot from; the
 * options of a round are drawn side by side in one batch.
 */
#[DeleteWhenMissingModels]
class GeneratePlateOption implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        public readonly int $variation,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $painter->paintPlateOption($this->shot->load('project'), $this->variation);
    }
}
