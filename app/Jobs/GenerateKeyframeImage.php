<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Keyframe;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Renders a single keyframe again, for example after the director changed
 * its description. Like the full render it attaches the style sheet, its
 * cast and sets, keyframe 1 and the keyframe just before it, where they apply.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframeImage implements ShouldQueue
{
    use MarksRenderFailures;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Keyframe $keyframe,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->keyframe->shot()->with('project')->firstOrFail();
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);

        $keyframe = $siblings->firstWhere('id', $this->keyframe->id) ?? $this->keyframe->setRelation('shot', $shot);
        $painter->render($keyframe, $siblings);

        GenerationFinished::ready(
            __('Keyframe :number of “:shot” is ready', ['number' => $keyframe->position, 'shot' => $shot->title]),
            route('public.shots.view', [$shot->project, $shot]),
            $keyframe->refresh()->render(),
            Keyframe::THUMBNAIL,
        )->sendTo($shot->project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markRenderFailed($this->keyframe, __('The image could not be generated. Please try again.'), $exception);

        $shot = $this->keyframe->shot()->with('project')->first();

        if ($shot !== null) {
            GenerationFinished::failed(__('Keyframe :number of “:shot” could not be drawn', ['number' => $this->keyframe->position, 'shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
                ->sendTo($shot->project);
        }
    }
}
