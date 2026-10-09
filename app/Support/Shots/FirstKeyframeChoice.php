<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Jobs\AdjustPlateOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The step between the plan and the keyframes: the director chooses the place
 * to draw on or an option for keyframe 1, and confirms keyframe 1 drawn on
 * the chosen place. Made by clicking a picture or by saying so in the chat.
 */
class FirstKeyframeChoice
{
    /** Places to choose from. */
    public const PLACES = 'places';

    /** Options for keyframe 1 to choose from. */
    public const OPTIONS = 'options';

    /** Keyframe 1 drawn on the chosen place, waiting to be confirmed. */
    public const CONFIRM = 'confirm';

    public function __construct(private readonly KeyframePainter $painter) {}

    /**
     * What waits for the director's choice, with the pictures in the order
     * they are shown and numbered; null when nothing waits for a choice.
     *
     * @return array{step: string, options: Collection<int, Media>}|null
     */
    public function waiting(Shot $shot): ?array
    {
        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY) {
            return null;
        }

        $first = $shot->keyframes()->with('media')->first();

        return match (true) {
            $first === null => null,
            $shot->hasChosenPlate() => ['step' => self::CONFIRM, 'options' => collect([$first->render()])->filter()->values()],
            $shot->getMedia(Shot::PLATE_OPTIONS)->isNotEmpty() => ['step' => self::PLACES, 'options' => $shot->getMedia(Shot::PLATE_OPTIONS)->values()],
            default => ['step' => self::OPTIONS, 'options' => $first->renders()->values()],
        };
    }

    /**
     * What the plan director says once keyframe 1 is drawn on the chosen place.
     */
    public static function confirmQuestion(): string
    {
        return __('Keyframe 1 is ready. Do you want this one? Click it or say yes, or tell me what to do differently.');
    }

    /**
     * Choose the empty place the shot starts from, and draw keyframe 1 on it.
     */
    public function choosePlace(Shot $shot, Media $option): void
    {
        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY || $shot->hasChosenPlate()) {
            throw ValidationException::withMessages(['plate' => __('A place can be chosen once the places are drawn.')]);
        }

        if ($shot->keyframes()->where('position', 1)->where('rendering', true)->exists()) {
            throw ValidationException::withMessages(['plate' => __('Wait until the adjusted place is drawn.')]);
        }

        $plate = $option->copy($shot, Shot::PLATE);
        $plate->setCustomProperty(Shot::PLATE_CHOSEN, true)->setCustomProperty('option', $option->id)->save();

        GenerateRemainingKeyframes::startOnPlate($shot, $this->painter);

        // Shots of the sequence that play in this place can be drawn now.
        Shot::drawWaitingShots($shot->project_id);
    }

    /**
     * Use a version of keyframe 1 and draw the other keyframes from it: an
     * option to choose from, or keyframe 1 drawn on the chosen place.
     */
    public function useFirst(Shot $shot, Media $render): void
    {
        $this->ensureChoosing($shot, 'render');

        $first = $shot->keyframes()->firstOrFail();

        if ($first->rendering) {
            throw ValidationException::withMessages(['render' => __('Wait until the adjusted option is drawn.')]);
        }

        $first->forceFill(['render_id' => $render->id])->save();

        GenerateRemainingKeyframes::startFor($shot);
    }

    /**
     * Draw more places or options for keyframe 1; on a chosen place, keyframe 1 again.
     */
    public function more(Shot $shot): void
    {
        $this->ensureChoosing($shot, 'keyframes');

        if ($shot->keyframes()->where('position', 1)->where('rendering', true)->exists()) {
            throw ValidationException::withMessages(['keyframes' => __('Wait until the adjusted option is drawn.')]);
        }

        $shot->keyframes()->where('position', 1)->update(['rendering' => true, 'render_error' => null]);

        $shot->forceFill([
            'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
            'storyline_error' => null,
        ])->save();

        $shot->hasChosenPlate()
            ? GenerateRemainingKeyframes::dispatch($shot, onlyFirst: true)
            : GenerateKeyframes::dispatch($shot, more: true);
    }

    /**
     * Go back to the places, dropping keyframe 1 drawn on the chosen one.
     */
    public function anotherPlace(Shot $shot): void
    {
        $first = $shot->keyframes()->firstOrFail();

        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY || ! $shot->hasChosenPlate() || $first->rendering) {
            throw ValidationException::withMessages(['plate' => __('Another place can be chosen while keyframe 1 waits for confirmation.')]);
        }

        $shot->clearMediaCollection(Shot::PLATE);
        $first->clearMediaCollection(Keyframe::RENDERS);
        $first->forceFill(['render_id' => null, 'render_error' => null])->save();
        $shot->forceFill(['storyline_error' => null])->save();
    }

    /**
     * Adjust one place or option before choosing it; the result is added as
     * a new one, so the original stays available.
     */
    public function adjust(Shot $shot, Media $option, string $instruction, bool $rewrite = true): void
    {
        $this->ensureChoosing($shot, 'instruction');

        if ($option->collection_name === Shot::PLATE_OPTIONS && $shot->hasChosenPlate()) {
            throw ValidationException::withMessages(['instruction' => __('A place can be adjusted while the places wait for a choice.')]);
        }

        $first = $shot->keyframes()->firstOrFail();

        if ($first->rendering) {
            throw ValidationException::withMessages(['instruction' => __('Wait until the current adjustment is done.')]);
        }

        $first->forceFill(['rendering' => true, 'render_error' => null])->save();
        $shot->forceFill(['storyline_error' => null])->save();

        $option->collection_name === Shot::PLATE_OPTIONS
            ? AdjustPlateOption::dispatch($shot, $option->id, $instruction)
            : TweakKeyframeImage::dispatch($first, $instruction, $option->id, rewrite: $rewrite);
    }

    /**
     * Fails with a message on the field when keyframe 1 is not waiting for a choice.
     */
    public function ensureChoosing(Shot $shot, string $field): void
    {
        if ($shot->status !== ShotStatus::FIRST_KEYFRAME_READY) {
            throw ValidationException::withMessages([$field => __('The first keyframe is not waiting for a choice.')]);
        }
    }
}
