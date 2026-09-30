<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Models\Keyframe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Renders a single keyframe again, for example after the director changed
 * its description. Keyframes after the first one use the first render as
 * their reference, like the full render does.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframeImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Keyframe $keyframe,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $keyframe = $this->keyframe->load('shot.project');
        $first = $keyframe->position > 1 ? $keyframe->shot->keyframes()->first()?->render() : null;

        $painter->paint($keyframe, $keyframe->prompt, $first ? [$painter->referenceFor($first)] : []);
    }

    public function failed(?Throwable $exception): void
    {
        $this->keyframe->forceFill([
            'rendering' => false,
            'render_error' => __('The image could not be generated. Please try again.'),
        ])->save();
    }
}
