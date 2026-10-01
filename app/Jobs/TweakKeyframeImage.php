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
use RuntimeException;
use Throwable;

/**
 * Corrects the current render of a keyframe: the image model gets the render
 * itself plus the director's instruction, so only the requested detail changes.
 * The keyframe before it is attached as context, so a missing object can be
 * copied from where it last appeared.
 */
#[DeleteWhenMissingModels]
class TweakKeyframeImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $keyframe = $this->keyframe->load('shot.project');
        $current = $keyframe->render() ?? throw new RuntimeException('The keyframe has no render to tweak.');
        $previous = $keyframe->position > 1
            ? $keyframe->shot->keyframes()->with('media')->where('position', $keyframe->position - 1)->first()?->render()
            : null;

        $painter->paint(
            $keyframe,
            KeyframeImageBrief::tweak($this->instruction, withPreviousKeyframe: $previous !== null),
            $previous ? [$painter->referenceFor($current), $painter->referenceFor($previous)] : [$painter->referenceFor($current)],
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->keyframe->forceFill([
            'rendering' => false,
            'render_error' => __('The image could not be adjusted. Please try again.'),
        ])->save();
    }
}
