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

        $painter->paint($keyframe, KeyframeImageBrief::tweak($this->instruction), [$painter->referenceFor($current)]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->keyframe->forceFill([
            'rendering' => false,
            'render_error' => __('The image could not be adjusted. Please try again.'),
        ])->save();
    }
}
