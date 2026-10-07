<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Adjusts one empty place before the director chooses it. The adjusted
 * place is added as a new one; while it is drawn, keyframe 1 shows as busy.
 */
#[DeleteWhenMissingModels]
class AdjustPlateOption implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        public readonly int $option,
        public readonly string $instruction,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load(['project', 'media']);
        $option = $shot->getMedia(Shot::PLATE_OPTIONS)->firstWhere('id', $this->option)
            ?? throw new RuntimeException('The place to adjust no longer exists.');

        $painter->adjustPlateOption($shot, $option, $this->instruction);

        $shot->keyframes()->where('position', 1)->update(['rendering' => false, 'render_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->keyframes()->where('position', 1)->update(['rendering' => false]);
        $this->shot->forceFill(['storyline_error' => __('The place could not be adjusted. Please try again.')])->save();
    }
}
