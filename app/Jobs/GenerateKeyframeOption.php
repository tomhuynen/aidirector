<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Jobs\Concerns\RetriesHiccups;
use App\Models\Keyframe;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Draws one option for the start frame. The options of a round run side by side
 * in one batch; {@see GenerateKeyframes::finishOptions()} runs when all are done.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframeOption implements ShouldQueue
{
    use Batchable;
    use FollowsPlan;
    use Queueable;
    use RetriesHiccups;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $variation,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    public function handle(KeyframePainter $painter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = $this->keyframe->shot()->with('project')->firstOrFail();
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $first = $siblings->firstWhere('id', $this->keyframe->id) ?? $this->keyframe->setRelation('shot', $shot);

        $this->retryingHiccups(fn() => $painter->render($first, $siblings, KeyframeImageBrief::variation($this->variation), choose: false));
    }
}
