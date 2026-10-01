<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Renders keyframes 2 to N once the director has chosen keyframe 1, each with
 * the style sheet and the chosen first keyframe as references.
 */
#[DeleteWhenMissingModels]
class GenerateRemainingKeyframes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load(['project', 'keyframes.media']);
        $first = $shot->keyframes->first()?->render() ?? throw new RuntimeException('Choose the first keyframe before rendering the others.');

        $references = array_values(array_filter([
            $painter->styleReferenceFor($shot->project),
            $painter->referenceFor($first),
        ]));

        $shot->keyframes
            ->skip(1)
            ->each(fn(Keyframe $keyframe) => $painter->paint($keyframe->setRelation('shot', $shot), $keyframe->prompt, $references));

        $shot->forceFill([
            'storyline_error' => null,
            'status' => ShotStatus::KEYFRAMES_READY,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->keyframes()->where('rendering', true)->update([
            'rendering' => false,
            'render_error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill([
            'storyline_error' => __('The other keyframes could not be rendered. Please try again.'),
            'status' => ShotStatus::FIRST_KEYFRAME_READY,
        ])->save();
    }
}
