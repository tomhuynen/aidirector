<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Renders keyframes 2 to N once keyframe 1 is chosen; with `$onlyFirst`,
 * keyframe 1 on the chosen place, for the director to confirm first. Elements without a reference image get one first, side by side
 * in a batch of {@see GenerateElementReference} jobs; then every
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
        public readonly bool $elementsDrawn = false,
        public readonly bool $onlyFirst = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $first = $siblings->first();
        $plate = $painter->chosenPlate($shot);

        if ($first === null || ($plate === null && $first->render() === null)) {
            throw new RuntimeException('Choose the first keyframe before rendering the others.');
        }

        if (! $this->elementsDrawn && $this->drawMissingElements($shot, $siblings, $first)) {
            return;
        }

        if ($this->onlyFirst) {
            $this->drawFirst($painter, $shot, $siblings, $first);

            return;
        }

        // Keyframe 1 with people gets a version without them, for the other keyframes to be drawn on; a chosen place is that already.
        if ($plate === null) {
            $painter->ensurePlate($shot, $siblings);
        }

        foreach ($siblings->skip(1) as $keyframe) {
            $painter->render($keyframe, $siblings);
            $keyframe->load('media');
        }

        $shot->forceFill([
            'storyline_error' => null,
            'keyframe_review' => null,
            'status' => ShotStatus::KEYFRAMES_READY,
        ])->save();

        // The keyframes are shown straight away; the review of them together follows as its own job.
        ReviewShot::after($shot);

        GenerationFinished::ready(__('All keyframes of “:shot” are ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]), $siblings->last()?->render(), Keyframe::THUMBNAIL)
            ->sendTo($shot->project);
    }

    /**
     * Draws keyframe 1 on the chosen place and waits for the director to
     * confirm it before the others are drawn.
     *
     * @param  \Illuminate\Support\Collection<int, Keyframe>  $siblings
     */
    private function drawFirst(KeyframePainter $painter, Shot $shot, \Illuminate\Support\Collection $siblings, Keyframe $first): void
    {
        $painter->render($first, $siblings);

        $shot->forceFill([
            'storyline_error' => null,
            'status' => ShotStatus::FIRST_KEYFRAME_READY,
        ])->save();

        GenerationFinished::ready(__('The first keyframe of “:shot” is ready to confirm', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]), $first->refresh()->render(), Keyframe::THUMBNAIL)
            ->sendTo($shot->project);
    }

    /**
     * Draws the reference images of new cast and sets side by side, then
     * starts this job again to render the keyframes. Returns false when every
     * element already has an image.
     *
     * @param  \Illuminate\Support\Collection<int, Keyframe>  $siblings
     */
    private function drawMissingElements(Shot $shot, \Illuminate\Support\Collection $siblings, Keyframe $first): bool
    {
        $missing = $siblings
            ->flatMap(fn(Keyframe $keyframe) => $keyframe->elements)
            ->unique('id')
            ->filter(fn(Element $element) => $element->reference() === null)
            ->values();

        if ($missing->isEmpty()) {
            return false;
        }

        $shotId = $shot->id;
        $onlyFirst = $this->onlyFirst;

        Bus::batch($missing->map(fn(Element $element) => new GenerateElementReference(
            $element,
            $first->render() !== null && $first->elements->contains('id', $element->id) ? $first : null,
        ))->all())
            ->name("Cast and sets for shot {$shot->id}")
            ->onQueue(Config::get('pipeline.queue'))
            ->allowFailures()
            ->finally(static function () use ($shotId, $onlyFirst) {
                $shot = Shot::query()->find($shotId);

                if ($shot !== null) {
                    self::dispatch($shot, elementsDrawn: true, onlyFirst: $onlyFirst);
                }
            })
            ->dispatch();

        return true;
    }

    /**
     * Starts drawing keyframe 1 on the chosen place. A keyframe 1 without
     * people is that place itself, so the others start straight away.
     */
    public static function startOnPlate(Shot $shot, KeyframePainter $painter): void
    {
        $first = $shot->keyframes()->with(['media', 'elements'])->firstOrFail();
        $plate = $painter->chosenPlate($shot) ?? throw new RuntimeException('Choose a place first.');

        if (! $painter->needsPlate($first)) {
            $painter->useChosenPlate($first, $plate);
            self::startFor($shot);

            return;
        }

        $first->forceFill(['rendering' => true, 'render_error' => null])->save();

        $shot->forceFill([
            'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
            'storyline_error' => null,
        ])->save();

        self::dispatch($shot, onlyFirst: true);
    }

    /**
     * Starts rendering keyframes 2 to N, once keyframe 1 is chosen.
     */
    public static function startFor(Shot $shot): void
    {
        $shot->keyframes()->where('position', '>', 1)->update(['rendering' => true, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline_error' => null,
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
            'storyline_error' => $this->onlyFirst
                ? __('The first keyframe could not be drawn on this place. Try again or choose another place.')
                : __('The other keyframes could not be rendered. Please try again.'),
            'status' => ShotStatus::FIRST_KEYFRAME_READY,
        ])->save();

        GenerationFinished::failed(__('The keyframes of “:shot” could not be rendered', ['shot' => $this->shot->title]), route('public.shots.view', [$this->shot->project, $this->shot]))
            ->sendTo($this->shot->project);
    }
}
