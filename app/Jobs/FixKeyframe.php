<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeChain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Fixes what the checks found in a keyframe, only where each problem is,
 * keeping everything else of the image.
 */
#[DeleteWhenMissingModels]
class FixKeyframe implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $version,
        public readonly array $problems,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    public function handle(KeyframePainter $painter, KeyframeChain $chain): void
    {
        if (! $chain->wanted($this->keyframe, $this->version)) {
            return;
        }

        $shot = $this->keyframe->shot()->with('project')->firstOrFail();
        $keyframe = $this->keyframe->load(['media', 'elements.media'])->setRelation('shot', $shot);

        $painter->repair($keyframe, $this->problems);

        if ($chain->wanted($this->keyframe, $this->version) && ! $this->planReplaced()) {
            $chain->changed($keyframe->refresh());
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        app(KeyframeChain::class)->failed($this->keyframe, $this->version, __('The fix could not be drawn. Please try again.'), $exception);
    }
}
