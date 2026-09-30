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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Renders an image for every keyframe in the shot's storyline. The first
 * keyframe is generated from its prompt alone; the following ones get the
 * first render as a reference so the character and scene stay consistent.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframes implements ShouldQueue
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
        $shot = $this->shot->load('project');
        $style = $painter->styleReferenceFor($shot->project);
        $first = null;

        foreach ($this->replaceKeyframes($shot, $style !== null) as $keyframe) {
            $render = $painter->paint($keyframe, $keyframe->prompt, array_values(array_filter([$style, $first])));

            $first ??= $painter->referenceFor($render);
        }

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
            'storyline_error' => __('The keyframe images could not be generated. Please try again.'),
            'status' => ShotStatus::STORYLINE_READY,
        ])->save();
    }

    /**
     * Replace the shot's keyframes with fresh rows for the current plan.
     *
     * @return Collection<int, Keyframe>
     */
    private function replaceKeyframes(Shot $shot, bool $withStyleReference): Collection
    {
        $shot->forgetKeyframes();

        return collect($shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => $shot->keyframes()->create([
                'position' => $index + 1,
                'title' => $keyframe['title'],
                'description' => $keyframe['description'],
                'prompt' => KeyframeImageBrief::for($shot, $keyframe, $withStyleReference, withFirstKeyframe: $index > 0),
                'rendering' => true,
            ])->setRelation('shot', $shot));
    }
}
