<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ElementPainter;
use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws the reference image of one new cast or set element. The elements of
 * a shot are drawn side by side; a failure is logged and never blocks the
 * keyframes, which start once the whole batch is done.
 */
#[DeleteWhenMissingModels]
class GenerateElementReference implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Element $element,
        public readonly ?Keyframe $source = null,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ElementPainter $painter): void
    {
        if ($this->batch()?->cancelled() || $this->element->reference() !== null) {
            return;
        }

        try {
            $painter->paint($this->element->load('project'), $this->source?->load(['media', 'shot.project']));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
