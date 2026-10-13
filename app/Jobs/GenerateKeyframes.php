<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\KeyframePainter;
use App\Enums\ShotStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Images\PlaceDepth;
use App\Support\Shots\FirstKeyframeChoice;
use App\Support\Shots\SequencePlaces;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

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

        // A shot with its own view of the place of its sequence: what its camera sees is written out first, so its place or keyframe 1 is drawn from there.
        app(SequencePlaces::class)->writeCameraBrief($shot);
        // A shot that cuts in on a keyframe of the shot before, such as a close-up at a window: what its own camera sees is written out first too.
        app(KeyframePainter::class)->writeCutInBrief($shot);
        $shot->refresh();

        $shotId = $shot->id;
        $planVersion = $this->planVersion;

        // A montage or a presenter has no shared place: every keyframe is drawn on its own, straight away.
        if ($shot->drawsStandalone()) {
            $shot->clearMediaCollection(Shot::PLATE_OPTIONS);
            $shot->clearMediaCollection(Shot::PLATE);
            GenerateRemainingKeyframes::startStandalone($shot);

            return;
        }

        // Start from empty places: the director chooses one, and every keyframe is drawn on it.
        // A close-up has no empty place to start from: its hands and object are on a person or a thing, so keyframe 1 is drawn with them.
        if (Config::get('pipeline.keyframes.start_with_plate') && $shot->kindOrScene()->usesPlace()) {
            if (! $this->more) {
                $shot->clearMediaCollection(Shot::PLATE_OPTIONS);
                $shot->clearMediaCollection(Shot::PLATE);
                $shot->clearMediaCollection(Shot::BLOCKING);
            }

            // In the place of its sequence, with the master the director chose: cut from it, with the cast where they stand there, nothing to choose.
            if (! $this->more && ($cut = app(KeyframePainter::class)->masterPlate($shot)) !== null) {
                // Already without its people and what moves; the whole place keeps the depth measured on it, a closer cut is measured now.
                $plate = $shot->addMediaFromString($cut['plate'])->usingFileName('place.png')->withCustomProperties(array_filter([Shot::PLATE_CHOSEN => true, Shot::PLATE_EMPTIED_DONE => true, PlaceDepth::DEPTH => $cut['depth']]))->toMediaCollection(Shot::PLATE);

                if ($cut['depth'] === null) {
                    MeasurePlaceDepth::dispatch($plate);
                }

                $shot->addMediaFromString($cut['blocking'])->usingFileName('blocking.png')->toMediaCollection(Shot::BLOCKING);
                GenerateRemainingKeyframes::startOnPlate($shot->refresh(), app(KeyframePainter::class));

                return;
            }

            // The same place as another shot of the sequence: its chosen place is used as it is, with nothing to choose.
            if (! $this->more && ($inherited = $shot->inheritedPlate()) !== null) {
                $inherited->copy($shot, Shot::PLATE)->setCustomProperty(Shot::PLATE_CHOSEN, true)->save();
                GenerateRemainingKeyframes::startOnPlate($shot->refresh(), app(KeyframePainter::class));

                return;
            }

            $drawnPlates = $shot->getMedia(Shot::PLATE_OPTIONS)->count();

            Bus::batch(collect(range(0, max(1, (int) Config::get('pipeline.keyframes.plate_options')) - 1))->map(fn(int $index) => new GeneratePlateOption($shot, $drawnPlates + $index))->all())
                ->name("Places for shot {$shot->id}")
                ->onQueue(Config::get('pipeline.queue'))
                ->allowFailures()
                ->finally(static fn(Batch $batch) => self::finishPlates($shotId, $batch->failedJobs, $planVersion))
                ->dispatch();

            return;
        }

        $first = $shot->keyframes()->with('media')->firstOrFail();
        $drawn = $first->renders()->count();

        Bus::batch(collect(range(0, self::optionCount() - 1))->map(fn(int $index) => new GenerateKeyframeOption($first, $drawn + $index))->all())
            ->name("Keyframe 1 options for shot {$shot->id}")
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

        if ($hasOptions) {
            $shot->say(self::optionCount() === 1 ? FirstKeyframeChoice::confirmQuestion() : __('The options for keyframe 1 are ready. Click the one you like or tell me which one, or ask me for more options.'));
        }

        $url = route('public.shots.view', [$shot->project, $shot]);

        ($hasOptions
            ? GenerationFinished::ready(self::optionCount() === 1 ? __('The first keyframe of “:shot” is ready to confirm', ['shot' => $shot->title]) : __('Options for the first keyframe of “:shot” are ready', ['shot' => $shot->title]), $url, $first->renders()->last(), Keyframe::THUMBNAIL)
            : GenerationFinished::failed(__('The first keyframe of “:shot” could not be drawn', ['shot' => $shot->title]), $url))
            ->sendTo($shot->project);
    }

    /**
     * Once every place of a round is drawn or failed: wait for the director to
     * choose one when there is anything to choose from, otherwise report it.
     */
    public static function finishPlates(int $shotId, int $failed = 0, ?int $planVersion = null): void
    {
        $shot = Shot::query()->find($shotId);

        // Reopened meanwhile: the places of the old plan are not offered.
        if ($shot === null || ($planVersion !== null && $shot->plan_version !== $planVersion)) {
            return;
        }

        $hasPlates = $shot->getMedia(Shot::PLATE_OPTIONS)->isNotEmpty();
        $shot->keyframes()->update(['rendering' => false]);

        $shot->forceFill([
            'storyline_error' => match (true) {
                ! $hasPlates => __('The places could not be drawn. Please try again.'),
                $failed > 0 => __('Not every place could be drawn. Choose one of these or try again.'),
                default => null,
            },
            'status' => $hasPlates ? ShotStatus::FIRST_KEYFRAME_READY : ShotStatus::STORYLINE_READY,
        ])->save();

        if ($hasPlates) {
            $shot->say(__('The places are ready. Click the one you like or tell me which one, or ask me for more places.'));

            // Every option kept the camera of the place of the sequence: the director hears it now, not at keyframe 1.
            if ($shot->getMedia(Shot::PLATE_OPTIONS)->every(fn(Media $option) => $option->getCustomProperty(GeneratePlateOption::SAME_CAMERA) === true)) {
                $shot->say(__('These places still show the place from about the same camera as the place chosen for the sequence, not from where this shot should look. Ask me for more places, or for a view from the side, which works more reliably than looking straight back.'));
            }
        }

        $url = route('public.shots.view', [$shot->project, $shot]);

        ($hasPlates
            ? GenerationFinished::ready(__('Places for “:shot” are ready to choose from', ['shot' => $shot->title]), $url)
            : GenerationFinished::failed(__('The places for “:shot” could not be drawn', ['shot' => $shot->title]), $url))
            ->sendTo($shot->project);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->planReplaced()) {
            return;
        }

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

        GenerationFinished::failed(__('The first keyframe of “:shot” could not be drawn', ['shot' => $this->shot->title]), route('public.shots.view', [$this->shot->project, $this->shot]))
            ->sendTo($this->shot->project);
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
                'spatial' => filled($plan['spatial'] ?? null) ? $plan['spatial'] : null,
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
