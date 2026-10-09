<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Makes a change the director asked for to the empty place, such as a door
 * handle taken away, and puts the keyframes from that one on onto the
 * changed place. The people are kept as they are; only a keyframe that is
 * not a composite is drawn again.
 */
#[DeleteWhenMissingModels]
class ChangePlace implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly Shot $shot,
        /** The first keyframe that gets the change. */
        public readonly int $from,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);

        $painter->ensurePlaceStates($shot, $siblings);

        foreach ($siblings->where('position', '>=', $this->from) as $keyframe) {
            if ($this->planReplaced()) {
                return;
            }

            $place = $painter->placeAt($shot, $siblings, $keyframe->position);

            if ($place === null || $painter->recomposite($keyframe, $place) === null) {
                $painter->render($keyframe, $siblings);
            }

            $keyframe->forceFill(['rendering' => false, 'render_error' => null])->save();
            $keyframe->load('media');
        }

        ReviewShot::after($shot);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        $this->shot->keyframes()->where('position', '>=', $this->from)->where('rendering', true)->update([
            'rendering' => false,
            'render_error' => __('The place could not be changed. Please try again.'),
        ]);

        report($exception);
    }
}
