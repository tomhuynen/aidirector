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
 * its description. Like the full render it attaches the project's style
 * sheet and, for keyframes after the first, the first keyframe's render.
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
        $style = $painter->styleReferenceFor($keyframe->shot->project);
        $first = $keyframe->position > 1 ? $keyframe->shot->keyframes()->first()?->render() : null;

        $painter->paint($keyframe, $keyframe->prompt, array_values(array_filter([
            $style,
            $first ? $painter->referenceFor($first) : null,
        ])));
    }

    public function failed(?Throwable $exception): void
    {
        $this->keyframe->forceFill([
            'rendering' => false,
            'render_error' => __('The image could not be generated. Please try again.'),
        ])->save();
    }
}
