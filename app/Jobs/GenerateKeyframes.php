<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws options for keyframe 1 for the director to choose from. The first run
 * replaces the shot's keyframes with fresh rows for the current plan; with
 * `$more` it adds another batch of options to the existing keyframe 1. The
 * other keyframes render after the choice, in {@see GenerateRemainingKeyframes}.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly Shot $shot,
        public readonly bool $more = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');
        $style = $painter->styleReferenceFor($shot->project);

        $first = $this->more ? $shot->keyframes()->firstOrFail() : $this->replaceKeyframes($shot, $style !== null);
        $first->setRelation('shot', $shot);

        $drawn = $first->renders()->count();

        foreach (range(0, self::optionCount() - 1) as $index) {
            $painter->paint(
                $first,
                $first->prompt . "\n" . KeyframeImageBrief::variation($drawn + $index),
                $style ? [$style] : [],
                choose: false,
            );
        }

        $first->forceFill(['render_id' => null, 'rendering' => false, 'render_error' => null])->save();

        $shot->forceFill([
            'storyline_error' => null,
            'status' => ShotStatus::FIRST_KEYFRAME_READY,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->keyframes()->where('rendering', true)->update([
            'rendering' => false,
            'render_error' => $exception?->getMessage(),
        ]);

        $hasOptions = $this->shot->keyframes()->first()?->renders()->isNotEmpty() ?? false;

        $this->shot->forceFill([
            'storyline_error' => $hasOptions
                ? __('Not every option could be drawn. Choose one of these or try again.')
                : __('The keyframe images could not be generated. Please try again.'),
            'status' => $hasOptions ? ShotStatus::FIRST_KEYFRAME_READY : ShotStatus::STORYLINE_READY,
        ])->save();
    }

    public static function optionCount(): int
    {
        return max(1, (int) Config::get('pipeline.keyframes.first_options'));
    }

    /**
     * Replace the shot's keyframes with fresh rows for the current plan and return keyframe 1.
     */
    private function replaceKeyframes(Shot $shot, bool $withStyleReference): Keyframe
    {
        $shot->forgetKeyframes();

        $keyframes = collect($shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => $shot->keyframes()->create([
                'position' => $index + 1,
                'title' => $keyframe['title'],
                'description' => $keyframe['description'],
                'prompt' => KeyframeImageBrief::for($shot, $keyframe, $withStyleReference, withFirstKeyframe: $index > 0),
                'rendering' => $index === 0,
            ]));

        return $keyframes->firstOrFail();
    }
}
