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
 * Changes one of the cast in a keyframe where they stand, as the director
 * confirmed in the chat, keeping everything else of the image. The
 * keyframes after it are drawn again in turn when the director asked for that.
 */
#[DeleteWhenMissingModels]
class ChangeCastMember implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $version,
        public readonly string $name,
        public readonly string $change,
        public readonly bool $following = false,
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

        $painter->change($keyframe, $this->name, $this->change);

        if ($chain->wanted($this->keyframe, $this->version) && ! $this->planReplaced()) {
            $chain->changed($keyframe->refresh(), $this->following);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        app(KeyframeChain::class)->failed($this->keyframe, $this->version, __('The change could not be drawn. Please try again.'), $exception);
    }
}
