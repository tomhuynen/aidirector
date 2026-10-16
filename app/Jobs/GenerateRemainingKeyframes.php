<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Jobs\Concerns\RetriesHiccups;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Throwable;

/**
 * Starts drawing a shot's keyframes once keyframe 1 is chosen: keyframe 1
 * is read first, so the others are drawn from where everyone stands in it. Cast and sets without a picture get one first, side by side in a
 * batch of {@see GenerateElementReference} jobs; then the keyframes are
 * drawn one after another by the {@see KeyframeChain}.
 */
#[DeleteWhenMissingModels]
class GenerateRemainingKeyframes implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;
    use RetriesHiccups;

    public int $timeout = 900;

    public function __construct(
        public readonly Shot $shot,
        public readonly bool $elementsDrawn = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function handle(KeyframePainter $painter, KeyframeChain $chain): void
    {
        $this->retryingHiccups(fn() => $this->start($painter, $chain));
    }

    private function start(KeyframePainter $painter, KeyframeChain $chain): void
    {
        $shot = $this->shot->refresh()->load('project');
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $first = $siblings->first();

        if ($first === null || (! $shot->drawsStandalone() && $first->render() === null)) {
            throw new RuntimeException('Choose the first keyframe before rendering the others.');
        }

        if (! $this->elementsDrawn && $this->drawMissingElements($shot, $siblings, $first)) {
            return;
        }

        // The chosen version of keyframe 1 is read once, before the keyframes after it are drawn from it.
        if (! $shot->drawsStandalone() && $first->layout === null && $painter->reads($first)) {
            $first->forceFill(['status' => KeyframeStatus::CHECKING])->save();
            $painter->readFirst($first, $first->render());
            $first->forceFill(['status' => KeyframeStatus::READY])->save();

            if ($this->planReplaced()) {
                return;
            }
        }

        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_PENDING, 'storyline_error' => null])->save();
        $chain->link($shot);
        // Every still of a montage or a presenter shot is drawn on its own; a scene from keyframe 2 on, in order.
        $chain->draw($shot->drawsStandalone() ? $siblings : $siblings->skip(1)->values());
    }

    /**
     * Starts drawing every keyframe of a montage or a presenter shot; there is no place or keyframe 1 to choose first.
     */
    public static function startStandalone(Shot $shot): void
    {
        $shot->keyframes()->update(['status' => KeyframeStatus::WAITING, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline_error' => null,
        ])->save();

        self::dispatch($shot);
    }

    /**
     * Draws the reference images of new cast and sets side by side, then
     * starts this job again to render the keyframes. Returns false when every
     * element already has an image.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    private function drawMissingElements(Shot $shot, Collection $siblings, Keyframe $first): bool
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
        $planVersion = $this->planVersion;

        Bus::batch($missing->map(fn(Element $element) => new GenerateElementReference(
            $element,
            $first->render() !== null && $first->elements->contains('id', $element->id) ? $first : null,
        ))->all())
            ->name("Cast and sets for shot {$shot->id}")
            ->onQueue(Config::get('pipeline.queue'))
            ->allowFailures()
            ->finally(static function () use ($shotId, $planVersion) {
                $shot = Shot::query()->find($shotId);

                // A plan that was reopened meanwhile is not drawn.
                if ($shot !== null && $shot->plan_version === $planVersion) {
                    self::dispatch($shot, elementsDrawn: true);
                }
            })
            ->dispatch();

        return true;
    }

    /**
     * Starts drawing keyframes 2 to N, once keyframe 1 is chosen.
     */
    public static function startFor(Shot $shot): void
    {
        $shot->keyframes()->where('position', '>', 1)->update(['status' => KeyframeStatus::WAITING, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline_error' => null,
        ])->save();

        self::dispatch($shot);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

        $this->shot->keyframes()->busy()->update([
            'status' => KeyframeStatus::FAILED,
            'render_error' => $exception?->getMessage(),
        ]);

        $this->shot->forceFill($this->shot->drawsStandalone() ? [
            'storyline_error' => __('Not every still could be drawn. Draw the missing ones again.'),
            'status' => ShotStatus::KEYFRAMES_READY,
        ] : [
            'storyline_error' => __('The other keyframes could not be rendered. Please try again.'),
            'status' => ShotStatus::FIRST_KEYFRAME_READY,
        ])->save();
    }
}
