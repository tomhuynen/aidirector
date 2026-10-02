<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Jobs\MergeShotVideos;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges adjacent shots into one whose video is their clips joined, and
 * splits it again. The merged shots are hidden from the sequence but kept
 * untouched as its parts, so unmerging puts them back exactly as they were.
 */
class MergeShots
{
    /**
     * @param  Collection<int, Shot>  $shots  shots of the project, in any order
     *
     * @throws ValidationException when the shots cannot be merged
     */
    public function merge(Project $project, Collection $shots, string $title, ShotTransition $transition): Shot
    {
        $sequence = $project->shots()->with('media')->get()->each->setRelation('project', $project)->values();
        $positions = $shots->map(fn(Shot $shot) => $sequence->search(fn(Shot $candidate) => $candidate->is($shot)))->sort()->values();

        if ($shots->count() < 2 || $positions->containsStrict(false)) {
            throw ValidationException::withMessages(['shots' => __('Pick at least two shots of this project.')]);
        }

        if ($positions->last() - $positions->first() !== $positions->count() - 1) {
            throw ValidationException::withMessages(['shots' => __('Only shots that follow each other can be merged.')]);
        }

        $parts = $positions->map(fn(int $index) => $sequence[$index])->values();

        if ($parts->contains(fn(Shot $shot) => $shot->isMerged())) {
            throw ValidationException::withMessages(['shots' => __('A merged shot cannot be merged again. Unmerge it first.')]);
        }

        if ($parts->contains(fn(Shot $shot) => $shot->video() === null)) {
            throw ValidationException::withMessages(['shots' => __('Every shot needs a video before it can be merged.')]);
        }

        /** @var Shot $first */
        $first = $parts->first();

        $merged = DB::connection($project->getConnectionName())->transaction(function () use ($project, $parts, $first, $title, $transition) {
            $merged = $project->allShots()->create([
                'position' => $first->position,
                'title' => $title,
                'takeaway' => $first->takeaway,
                'status' => ShotStatus::VIDEO_PENDING,
                'purpose_override' => $first->purpose_override,
                'aspect_ratio_override' => $first->aspect_ratio_override,
                'duration' => $parts->sum(fn(Shot $part) => $part->durationInSeconds()),
                'merge_transition' => $transition,
            ]);

            $parts->each(fn(Shot $part) => $part->forceFill(['merged_into_id' => $merged->id])->save());
            $this->renumber($project);

            return $merged;
        });

        MergeShotVideos::dispatch($merged);

        return $merged;
    }

    /**
     * Puts the parts back in the sequence where the merged shot was, and
     * removes the merged shot and its joined video.
     *
     * @return Shot the first part
     */
    public function unmerge(Shot $merged): Shot
    {
        $project = $merged->project()->firstOrFail();
        $parts = $merged->parts()->get();

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $merged, $parts) {
            $order = $project->shots()->get()
                ->flatMap(fn(Shot $shot) => $shot->is($merged) ? $parts : [$shot])
                ->values();

            // Released first, so deleting the merged shot leaves the parts alone.
            $parts->each(fn(Shot $part) => $part->forceFill(['merged_into_id' => null])->save());
            $merged->delete();

            $order->each(fn(Shot $shot, int $index) => $shot->forceFill(['position' => $index + 1])->save());
        });

        /** @var Shot */
        return $parts->first();
    }

    /**
     * Joins the clips again, after a part got a new video or with another transition.
     */
    public function rejoin(Shot $merged, ?ShotTransition $transition = null): void
    {
        $merged->forceFill([
            'merge_transition' => $transition ?? $merged->merge_transition,
            'status' => ShotStatus::VIDEO_PENDING,
            'video_error' => null,
        ])->save();

        MergeShotVideos::dispatch($merged);
    }

    private function renumber(Project $project): void
    {
        $project->shots()->orderBy('id')->get()
            ->sortBy('position')
            ->values()
            ->each(fn(Shot $shot, int $index) => $shot->forceFill(['position' => $index + 1])->save());
    }
}
