<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ElementPainter;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Element;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws or changes an element's reference image from its page: with an
 * instruction the current image is edited, without one it is drawn from the
 * description.
 */
#[DeleteWhenMissingModels]
class UpdateElementImage implements ShouldQueue
{
    use MarksRenderFailures;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Element $element,
        public readonly ?string $instruction = null,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ElementPainter $painter): void
    {
        $element = $this->element->load('project');

        filled($this->instruction) && $element->reference() !== null
            ? $painter->edit($element, (string) $this->instruction)
            : $painter->paint($element);

        $element->forceFill(['rendering' => false, 'render_error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->markRenderFailed($this->element, __('The image could not be generated. Please try again.'), $exception);
    }
}
