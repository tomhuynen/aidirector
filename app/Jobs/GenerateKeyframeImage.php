<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Briefs\KeyframeImageBrief;
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
 * sheet, keyframe 1 and the keyframe just before it, where they apply.
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
        $siblings = $keyframe->shot->keyframes()->with('media')->get();
        $first = KeyframeImageBrief::usesFirstKeyframe($keyframe->position) ? $siblings->first()?->render() : null;
        $previous = KeyframeImageBrief::usesPreviousKeyframe($keyframe->position)
            ? $siblings->firstWhere('position', $keyframe->position - 1)?->render()
            : null;

        $painter->paint($keyframe, $keyframe->prompt, array_values(array_filter([
            $painter->styleReferenceFor($keyframe->shot->project),
            $first ? $painter->referenceFor($first) : null,
            $previous ? $painter->referenceFor($previous) : null,
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
