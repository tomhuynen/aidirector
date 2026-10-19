<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws options for the start frame for the director to choose from, side by
 * side in one batch of {@see GenerateKeyframeOption} jobs. The first run
 * replaces the shot's keyframes with fresh rows for the current plan, linked
 * to the cast and sets the plan names; with `$more` it adds another batch of
 * options to the existing start frame. The end frame is drawn after the
 * choice, in {@see GenerateRemainingKeyframes}. A shot that opens with a copy
 * keeps its start frame and goes on from it straight away, and a shot that
 * continues the shot before has its one start frame drawn from that end
 * frame, with no options.
 */
#[DeleteWhenMissingModels]
class GenerateKeyframes implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Shot $shot,
        public readonly bool $more = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->shot);
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');

        if (! $this->more) {
            $this->replaceKeyframes($shot);
        }

        $shotId = $shot->id;
        $planVersion = $this->planVersion;

        // A montage or a presenter has no shared place: every keyframe is drawn on its own, straight away.
        if ($shot->drawsStandalone()) {
            GenerateRemainingKeyframes::startStandalone($shot);

            return;
        }

        if ($shot->opensWithCopy()) {
            $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY, 'storyline_error' => null])->save();
            GenerateRemainingKeyframes::dispatch($shot);

            return;
        }

        // A match cut has one start frame: the end frame of the shot before, seen from this shot's camera.
        if ($shot->continuesPose()) {
            GenerateRemainingKeyframes::startContinuation($shot);

            return;
        }

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $drawn = $first->renders()->count();

        Bus::batch(collect(range(0, self::optionCount() - 1))->map(fn(int $index) => new GenerateKeyframeOption($first, $drawn + $index))->all())
            ->name("Start frame options for shot {$shot->id}")
            ->onQueue(Config::get('pipeline.queue'))
            ->allowFailures()
            ->finally(static fn(Batch $batch) => self::finishOptions($shotId, $batch->failedJobs, $planVersion))
            ->dispatch();
    }

    /**
     * Once every option of a round is drawn or failed: wait for the director's
     * choice when there is anything to choose from, otherwise report the failure.
     */
    public static function finishOptions(int $shotId, int $failed = 0, ?int $planVersion = null): void
    {
        $shot = Shot::query()->find($shotId);
        $first = $shot?->keyframes()->with('media')->first();

        if ($shot === null || $first === null || ($planVersion !== null && $shot->plan_version !== $planVersion)) {
            return;
        }

        $hasOptions = $first->renders()->isNotEmpty();

        // The options wait for the director's choice; the start frame is ready once one is chosen.
        $first->forceFill([
            'render_id' => null,
            'status' => $hasOptions ? KeyframeStatus::PLANNED : KeyframeStatus::FAILED,
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
        if ($this->planReplaced()) {
            return;
        }

        $this->shot->keyframes()->busy()->update([
            'status' => KeyframeStatus::FAILED,
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
        $kept = $shot->keyframes()->pluck('position')->all();

        $elements = $shot->project->elements()->get()->keyBy(fn(Element $element) => mb_strtolower(trim($element->name)));

        // A shot that renders in its sequence has one keyframe: the video model plays the act from it.
        $plans = $shot->rendersInSequence() ? array_slice($shot->storylineKeyframes(), 0, 1) : $shot->storylineKeyframes();

        foreach ($plans as $index => $plan) {
            if (in_array($index + 1, $kept, true)) {
                continue;
            }

            $keyframe = $shot->keyframes()->create([
                'position' => $index + 1,
                'title' => $plan['title'],
                'staging' => $plan['staging'] ?? null,
                'description' => $plan['description'],
                'spatial' => filled($plan['spatial'] ?? null) ? $plan['spatial'] : null,
                'status' => $index === 0 ? KeyframeStatus::DRAWING : KeyframeStatus::PLANNED,
            ]);

            $named = collect($plan['elements'] ?? [])
                ->map(fn(string $name) => $elements->get(mb_strtolower(trim($name)))?->id)
                ->filter()
                ->unique();

            $keyframe->elements()->sync($named->all());
        }

        app(KeyframeChain::class)->link($shot);
    }
}
