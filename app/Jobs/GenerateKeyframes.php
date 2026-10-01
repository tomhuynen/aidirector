<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotStatus;
use App\Models\Element;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws options for keyframe 1 for the director to choose from, side by side
 * in one batch of {@see GenerateKeyframeOption} jobs. The first run
 * replaces the shot's keyframes with fresh rows for the current plan, linked
 * to the cast and sets the plan names; with
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

    public function handle(): void
    {
        $shot = $this->shot->load('project');

        if (! $this->more) {
            $this->replaceKeyframes($shot);
        }

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $drawn = $first->renders()->count();
        $shotId = $shot->id;

        Bus::batch(collect(range(0, self::optionCount() - 1))->map(fn(int $index) => new GenerateKeyframeOption($first, $drawn + $index))->all())
            ->name("Keyframe 1 options for shot {$shot->id}")
            ->onQueue(Config::get('pipeline.queue'))
            ->allowFailures()
            ->finally(static fn(Batch $batch) => self::finishOptions($shotId, $batch->failedJobs))
            ->dispatch();
    }

    /**
     * Once every option of a round is drawn or failed: wait for the director's
     * choice when there is anything to choose from, otherwise report the failure.
     */
    public static function finishOptions(int $shotId, int $failed = 0): void
    {
        $shot = Shot::query()->find($shotId);
        $first = $shot?->keyframes()->with('media')->first();

        if ($shot === null || $first === null) {
            return;
        }

        $hasOptions = $first->renders()->isNotEmpty();

        $first->forceFill([
            'render_id' => null,
            'rendering' => false,
            'render_error' => $hasOptions ? null : __('No option could be drawn.'),
        ])->save();

        $shot->forceFill([
            'storyline_error' => match (true) {
                ! $hasOptions => __('The keyframe images could not be generated. Please try again.'),
                $failed > 0 => __('Not every option could be drawn. Choose one of these or try again.'),
                default => null,
            },
            'status' => $hasOptions ? ShotStatus::FIRST_KEYFRAME_READY : ShotStatus::STORYLINE_READY,
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
     * Replace the shot's keyframes with fresh rows for the current plan, linked
     * to the cast and sets the plan names. Their prompts are written at render time.
     */
    private function replaceKeyframes(Shot $shot): void
    {
        $shot->forgetKeyframes();

        $elements = $shot->project->elements()->get()->keyBy(fn(Element $element) => mb_strtolower(trim($element->name)));

        foreach ($shot->storylineKeyframes() as $index => $plan) {
            $keyframe = $shot->keyframes()->create([
                'position' => $index + 1,
                'title' => $plan['title'],
                'description' => $plan['description'],
                'rendering' => $index === 0,
            ]);

            $named = collect($plan['elements'] ?? [])
                ->map(fn(string $name) => $elements->get(mb_strtolower(trim($name)))?->id)
                ->filter()
                ->unique();

            $keyframe->elements()->sync($named->all());
        }
    }
}
