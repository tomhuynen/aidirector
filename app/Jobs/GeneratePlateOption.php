<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Draws one empty place for the director to start the shot from; the
 * options of a round are drawn side by side in one batch.
 */
#[DeleteWhenMissingModels]
class GeneratePlateOption implements ShouldQueue
{
    use Batchable;
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** On a place option: it kept the camera of the chosen place, also when drawn again. */
    public const SAME_CAMERA = 'same_camera';

    /** Added when the first attempt kept the camera of the chosen place. */
    private const OTHER_CAMERA = 'The previous attempt copied the camera of the attached picture of the place. This time the camera must really stand where this shot\'s camera stands: draw the place from that position and direction, with the fixed things where the camera brief puts them, even when that means looking the other way.';

    public function __construct(
        public readonly Shot $shot,
        public readonly int $variation,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function handle(KeyframePainter $painter, ?SequencePlaces $places = null): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = $this->shot->load('project');
        $places ??= app(SequencePlaces::class);
        $option = $painter->paintPlateOption($shot, $this->variation);

        // A shot with its own view of the place of its sequence: the image model often keeps the camera of the chosen picture, so that is checked and drawn once more.
        if (! $places->sameCamera($shot, $option)) {
            return;
        }

        $option->delete();
        $option = $painter->paintPlateOption($shot, $this->variation, self::OTHER_CAMERA);

        if ($places->sameCamera($shot, $option)) {
            $option->setCustomProperty(self::SAME_CAMERA, true)->save();
        }
    }
}
