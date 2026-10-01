<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ElementPainter;
use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Renders keyframes 2 to N once keyframe 1 is chosen and its cast and sets
 * reviewed. Elements without a reference image get one first; then every
 * keyframe renders with the style sheet, its elements, the chosen first
 * keyframe and the keyframe just before it as references.
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

    public function handle(KeyframePainter $painter, ElementPainter $elements): void
    {
        $shot = $this->shot->load('project');
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $first = $siblings->first();

        if ($first?->render() === null) {
            throw new RuntimeException('Choose the first keyframe before rendering the others.');
        }

        $elements->paintMissing($siblings, $first);

        foreach ($siblings->skip(1) as $keyframe) {
            $painter->render($keyframe, $siblings);
        }

        $shot->forceFill([
            'storyline_error' => null,
            'status' => ShotStatus::KEYFRAMES_READY,
        ])->save();
    }

    /**
     * Starts rendering keyframes 2 to N, once keyframe 1 and its cast and sets are settled.
     */
    public static function startFor(Shot $shot): void
    {
        $shot->keyframes()->where('position', '>', 1)->update(['rendering' => true, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline_error' => null,
            'element_proposals' => null,
        ])->save();

        self::dispatch($shot);
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
