<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Keyframe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Checks after drawing, without holding the keyframe up, whether the place
 * stayed the same as in keyframe 1: shifted floor lines, door frames or
 * backgrounds. What it finds is kept on the render as notes the director can
 * have fixed or dismiss; it never redraws by itself.
 */
#[DeleteWhenMissingModels]
class CheckKeyframePlace implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly int $renderId,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    /**
     * Queue the place check for a render of a keyframe after keyframe 1, when checks are on.
     */
    public static function start(Keyframe $keyframe, Media $render): void
    {
        if (Config::get('pipeline.keyframe_check') && Config::get('pipeline.place_check') && $keyframe->position > 1) {
            // Shown as checking in the editor, without blocking the keyframe for changes.
            $keyframe->forceFill(['render_stage' => Keyframe::STAGE_CHECKING])->save();
            self::dispatch($keyframe, (int) $render->id);
        }
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->keyframe->shot()->with('project')->firstOrFail();
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $keyframe = $siblings->firstWhere('id', $this->keyframe->id);
        $render = $keyframe?->renders()->firstWhere('id', $this->renderId);

        try {
            // The keyframe was drawn again or moved to the front meanwhile.
            if ($keyframe === null || $render === null || $keyframe->position === 1) {
                return;
            }

            $issues = $painter->checkPlace($keyframe, $render, $painter->referencesFor($keyframe, $siblings));

            if ($issues !== null) {
                $render->setCustomProperty(Keyframe::PLACE_ISSUES, $issues)->save();
            }
        } finally {
            $this->doneChecking();
        }
    }

    public function failed(): void
    {
        $this->doneChecking();
    }

    /**
     * Clear the checking label, unless the keyframe is being drawn again and shows its own progress.
     */
    private function doneChecking(): void
    {
        Keyframe::query()
            ->whereKey($this->keyframe->getKey())
            ->where('render_stage', Keyframe::STAGE_CHECKING)
            ->where('rendering', false)
            ->update(['render_stage' => null]);
    }
}
