<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\DrawKeyframe;
use App\Jobs\ReviewShot;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Draws keyframes in the order they depend on each other. Every keyframe is
 * drawn from the keyframe before it, and keyframe 1 of a shot that continues
 * another from that shot's keyframe. A keyframe waits until the one it
 * depends on is ready. When a keyframe changes, what is drawn from it stays
 * as it is unless the director asks to update it too; then it is drawn
 * again, in turn. What was never drawn is always drawn.
 */
class KeyframeChain
{
    /**
     * Links every keyframe of the shot to the keyframe it is drawn from.
     */
    public function link(Shot $shot): void
    {
        $keyframes = $shot->keyframes()->orderBy('position')->get();
        $before = $this->continuesFrom($shot);

        foreach ($keyframes as $keyframe) {
            // A still of a montage or a presenter shot is drawn on its own.
            $dependsOn = $shot->drawsStandalone() ? null : $before?->id;

            if ($keyframe->depends_on_id !== $dependsOn) {
                $keyframe->forceFill(['depends_on_id' => $dependsOn])->save();
            }

            $before = $keyframe;
        }
    }

    /**
     * Asks for keyframes to be drawn: each waits for the keyframe it depends
     * on, so they are drawn one after another.
     *
     * @param  Collection<int, Keyframe>|Keyframe  $keyframes
     */
    public function draw(Collection|Keyframe $keyframes): void
    {
        $keyframes = $keyframes instanceof Keyframe ? collect([$keyframes]) : $keyframes;

        foreach ($keyframes as $keyframe) {
            $this->queue($keyframe, KeyframeStatus::WAITING);
        }

        $keyframes->pluck('shot_id')->unique()->each(fn(int $shotId) => $this->advance($shotId));
    }

    /**
     * Draws a keyframe again. What is drawn from it is drawn again after it
     * when `$following` is asked for, and always when it was never drawn.
     */
    public function redrawFrom(Keyframe $keyframe, bool $following = false): void
    {
        $this->queue($keyframe, KeyframeStatus::WAITING);

        $shotIds = [$keyframe->shot_id];

        foreach ($this->following($keyframe, $following) as $dependent) {
            $this->queue($dependent, KeyframeStatus::WAITING);
            $shotIds[] = $dependent->shot_id;
        }

        collect($shotIds)->unique()->each(fn(int $shotId) => $this->advance($shotId));
    }

    /**
     * A keyframe was changed in place, such as by an adjustment or a move: it
     * is ready. What is drawn from it is drawn again when `$following` is
     * asked for, and always when it was never drawn.
     */
    public function changed(Keyframe $keyframe, bool $following = false): void
    {
        $keyframe->forceFill(['status' => KeyframeStatus::READY, 'render_error' => null])->save();

        $dependents = $this->following($keyframe, $following);
        $dependents->each(fn(Keyframe $dependent) => $this->queue($dependent, KeyframeStatus::WAITING));
        $dependents->pluck('shot_id')->unique()->each(fn(int $shotId) => $this->advance($shotId));

        $this->finishShot($keyframe->shot()->with('project')->firstOrFail(), $keyframe);
    }

    /**
     * The keyframes after a changed one that are drawn again: all that are
     * drawn from it when the director asks for that, otherwise only those
     * never drawn, which would be missing.
     *
     * @return Collection<int, Keyframe>
     */
    private function following(Keyframe $keyframe, bool $following): Collection
    {
        return $this->drawnFrom($keyframe)
            ->filter(fn(Keyframe $dependent) => $following || $dependent->render_id === null)
            ->values();
    }

    /**
     * Starts every keyframe of the shot whose turn it is: it waits and what it
     * depends on is ready. A keyframe is only ever started once per request.
     */
    public function advance(int $shotId): void
    {
        $keyframes = Keyframe::query()
            ->with('dependsOn')
            ->where('shot_id', $shotId)
            ->where('status', KeyframeStatus::WAITING)
            ->orderBy('position')
            ->get();

        foreach ($keyframes as $keyframe) {
            if ($keyframe->dependsOn !== null && $keyframe->dependsOn->status !== KeyframeStatus::READY) {
                continue;
            }

            $started = Keyframe::query()
                ->whereKey($keyframe->id)
                ->where('draw_version', $keyframe->draw_version)
                ->where('status', KeyframeStatus::WAITING)
                ->update(['status' => KeyframeStatus::DRAWING]);

            if ($started === 1) {
                DrawKeyframe::dispatch($keyframe, $keyframe->draw_version);
            }
        }
    }

    /**
     * A keyframe is drawn and checked: what waits for it can start, and a
     * shot whose keyframes are all ready is done.
     */
    public function finished(Keyframe $keyframe): void
    {
        $keyframe->forceFill(['status' => KeyframeStatus::READY, 'render_error' => null])->save();

        $keyframe->dependents()->pluck('shot_id')->push($keyframe->shot_id)->unique()->each(fn(int $shotId) => $this->advance($shotId));

        $this->finishShot($keyframe->shot()->with('project')->firstOrFail(), $keyframe);
    }

    /**
     * Drawing a keyframe failed: it says why. What waits for it stops waiting
     * and is drawn again when the failed keyframe is; a shot with nothing left
     * to draw is no longer busy.
     */
    public function failed(Keyframe $keyframe, int $version, string $message, ?Throwable $exception): void
    {
        $reason = match (true) {
            $exception instanceof MaxAttemptsExceededException => __('It ran longer than the queue allows and was stopped.'),
            $exception !== null => Str::limit($exception->getMessage(), 300),
            default => null,
        };

        // Only the draw that is still wanted may fail the keyframe; a newer request has a draw of its own.
        $failed = Keyframe::query()
            ->whereKey($keyframe->id)
            ->where('draw_version', $version)
            ->update(['status' => KeyframeStatus::FAILED, 'render_error' => $reason === null ? $message : "{$message} {$reason}"]);

        if ($failed === 0) {
            return;
        }

        foreach ($this->drawnFrom($keyframe) as $dependent) {
            if ($dependent->status === KeyframeStatus::WAITING) {
                $dependent->forceFill(['status' => KeyframeStatus::PLANNED])->save();
            }
        }

        $shot = $keyframe->shot()->with('project')->first();

        if ($shot !== null) {
            if ($shot->status === ShotStatus::KEYFRAMES_PENDING && ! $shot->keyframes()->busy()->exists()) {
                $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY, 'storyline_error' => __('Not every keyframe could be drawn. Draw the missing ones again.')])->save();
            }

            GenerationFinished::failed(__('Keyframe :number of “:shot” could not be drawn', ['number' => $keyframe->position, 'shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
                ->sendTo($shot->project);
        }
    }

    /**
     * Whether a draw is still the one asked for last.
     */
    public function wanted(Keyframe $keyframe, int $version): bool
    {
        return Keyframe::query()->whereKey($keyframe->id)->where('draw_version', $version)->where('status', '!=', KeyframeStatus::FAILED)->exists();
    }

    private function queue(Keyframe $keyframe, KeyframeStatus $status): void
    {
        $keyframe->forceFill([
            'status' => $status,
            'draw_version' => $keyframe->draw_version + 1,
            'render_error' => null,
        ])->save();
    }

    /**
     * Every keyframe drawn from this one, directly or through others, in any shot.
     *
     * @return Collection<int, Keyframe>
     */
    private function drawnFrom(Keyframe $keyframe): Collection
    {
        $found = collect();
        $next = $keyframe->dependents()->get();

        while ($next->isNotEmpty()) {
            $fresh = $next->reject(fn(Keyframe $dependent) => $found->contains('id', $dependent->id) || $dependent->id === $keyframe->id);
            $found = $found->merge($fresh);
            $next = Keyframe::query()->whereIn('depends_on_id', $fresh->pluck('id'))->get();
        }

        return $found->values();
    }

    /**
     * The keyframe of another shot this shot's keyframe 1 is drawn from, if it continues one.
     */
    private function continuesFrom(Shot $shot): ?Keyframe
    {
        $from = $shot->settingFrom();

        if ($from === null || $from['keyframe'] === 0) {
            return null;
        }

        $source = $from['shot']->keyframes();

        return $from['keyframe'] === Shot::LAST_KEYFRAME
            ? $source->reorder('position', 'desc')->first()
            : $source->where('position', $from['keyframe'])->first();
    }

    /**
     * Once every keyframe of a shot is ready again, the shot is reviewed. When
     * its keyframes were being drawn, shots that wait for it can start; the
     * director hears of the whole shot, or of the one keyframe drawn again.
     */
    private function finishShot(Shot $shot, Keyframe $keyframe): void
    {
        if ($shot->keyframes()->where('status', '!=', KeyframeStatus::READY)->exists()) {
            return;
        }

        if ($shot->status !== ShotStatus::KEYFRAMES_PENDING) {
            ReviewShot::after($shot);

            GenerationFinished::ready(__('Keyframe :number of “:shot” is ready', ['number' => $keyframe->position, 'shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]), $keyframe->refresh()->render(), Keyframe::THUMBNAIL)
                ->sendTo($shot->project);

            return;
        }

        $shot->forceFill(['storyline_error' => null, 'keyframe_review' => null, 'status' => ShotStatus::KEYFRAMES_READY])->save();

        ReviewShot::after($shot);
        Shot::drawWaitingShots($shot->project_id);

        GenerationFinished::ready(__('All keyframes of “:shot” are ready', ['shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]), $shot->keyframes()->with('media')->reorder('position', 'desc')->first()?->render(), Keyframe::THUMBNAIL)
            ->sendTo($shot->project);
    }
}
