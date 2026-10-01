<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ElementPainter;
use App\Enums\ElementSuggestionStatus;
use App\Models\ElementSuggestion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Renders one cast and sets suggestion in the project style.
 */
#[DeleteWhenMissingModels]
class RenderElementSuggestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly ElementSuggestion $suggestion,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ElementPainter $painter): void
    {
        $suggestion = $this->suggestion->load(['round.project', 'sourcePhoto']);

        $painter->paintSuggestion($suggestion);

        $suggestion->forceFill(['status' => ElementSuggestionStatus::READY, 'error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->suggestion->forceFill([
            'status' => ElementSuggestionStatus::FAILED,
            'error' => __('This suggestion could not be drawn.'),
        ])->save();
    }
}
