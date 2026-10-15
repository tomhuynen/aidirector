<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Jobs\Concerns\RetriesHiccups;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeChain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws one keyframe when its turn in the {@see KeyframeChain} comes, from
 * the keyframe it depends on, and checks it. A draw that was asked for again
 * meanwhile stops, so an older draw never overwrites a newer one.
 */
#[DeleteWhenMissingModels]
class DrawKeyframe implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;
    use RetriesHiccups;

    public int $timeout = 600;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $version,
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
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $keyframe = $siblings->firstWhere('id', $this->keyframe->id) ?? $this->keyframe->setRelation('shot', $shot);

        $this->retryingHiccups(fn() => $painter->render($keyframe, $siblings));

        if ($this->job?->isReleased() || $this->job?->hasFailed()) {
            return;
        }

        if ($chain->wanted($this->keyframe, $this->version) && ! $this->planReplaced()) {
            $chain->finished($keyframe->refresh());
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        app(KeyframeChain::class)->failed($this->keyframe, $this->version, __('The image could not be generated. Please try again.'), $exception);
    }
}
