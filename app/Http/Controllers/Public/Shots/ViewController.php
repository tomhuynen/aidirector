<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\KeyframeResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Http\Resources\Public\ShotResource;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ViewController
{
    public function view(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::VIEW, $shot);

        // A part of a merged shot is changed by unmerging it first, so it opens the merged shot.
        if ($shot->merged_into_id !== null) {
            return redirect()->route('public.shots.view', [$project, $shot->mergedInto()->firstOrFail()]);
        }

        $shot->setRelation('project', $project);

        return Inertia::render('shots/view', [
            'project' => fn() => ProjectResource::make($project),
            'shot' => fn() => ShotResource::make($shot),
            /** @var array<int, array{value: string, label: string, description: string}> */
            'shotKinds' => fn() => ShotKind::catalogue(),
            'keyframes' => fn() => KeyframeResource::collection(
                $shot->keyframes()->with(['media', 'elements'])->get()->each->setRelation('shot', $shot)
            ),
            /** The cast and sets the director can ask the storylines to use. */
            'elements' => fn() => ElementResource::collection(
                $project->elements()->with('media')->get()->each->setRelation('project', $project)
            ),
            /**
             * The ways the clips of merged shots can follow each other.
             *
             * @var array<int, array{value: string, label: string}>
             */
            'shotTransitions' => fn() => $this->transitions(),
            /**
             * For a merged shot: its parts and how their clips are joined, or whether it is a sequence rendered in one clip.
             *
             * @var array{transition: string, transitions: array<int, array{value: string, label: string}>, sequence: bool, parts: array<int, array{id: string, title: string, storyline: string|null, duration: int, start: float|null, thumbnailUrl: string|null, videoUrl: string|null, repairUrl: string|null}>, links: array{update: string, unmerge: string}}|null
             */
            'merge' => fn() => $this->merge($project, $shot),
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'media', 'keyframes.media', 'parts.keyframes.media'])->withCount('parts')->get()
            ),
        ]);
    }

    /**
     * @return array{transition: string, transitions: array<int, array{value: string, label: string}>, sequence: bool, parts: array<int, array{id: string, title: string, storyline: string|null, duration: int, start: float|null, thumbnailUrl: string|null, videoUrl: string|null, repairUrl: string|null}>, links: array{update: string, unmerge: string}}|null
     */
    private function merge(Project $project, Shot $shot): ?array
    {
        if (! $shot->isMerged()) {
            return null;
        }

        $parts = $shot->parts()->with(['media', 'keyframes.media'])->get()->each->setRelation('project', $project);
        // A sequence rendered in one clip: each part can be rendered again in place once the clip is there.
        $sequence = $parts->every(fn(Shot $part) => $part->rendersInSequence());
        $starts = (array) $shot->video()?->getCustomProperty(Shot::PART_STARTS, []);
        $repairable = $sequence && $shot->status === ShotStatus::VIDEO_READY && $shot->video() !== null;

        return [
            'transition' => $shot->merge_transition->value,
            'transitions' => $this->transitions(),
            'sequence' => $sequence,
            'parts' => $parts->values()->map(fn(Shot $part, int $index) => [
                'id' => $part->sqid,
                'title' => $part->title,
                'storyline' => $part->chosen_storyline['storyline'] ?? null,
                'duration' => $part->durationInSeconds(),
                'start' => isset($starts[$index]) ? (float) $starts[$index] : null,
                'thumbnailUrl' => ShotListItemResource::make($part)->resolve()['thumbnailUrl'],
                'videoUrl' => ($video = $part->video()) === null ? null : URL::temporarySignedRoute('public.media.view', now()->addHours(2), ['media' => $video]),
                'repairUrl' => $repairable ? route('public.shots.merge.repair', [$project, $shot, $part]) : null,
            ])->all(),
            'links' => [
                'update' => route('public.shots.merge.update', [$project, $shot]),
                'unmerge' => route('public.shots.unmerge', [$project, $shot]),
            ],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function transitions(): array
    {
        return ShotTransition::collect()->map(fn(ShotTransition $transition) => [
            'value' => $transition->value,
            'label' => $transition->description(),
        ])->values()->all();
    }
}
