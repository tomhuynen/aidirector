<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotTransition;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\KeyframeResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Http\Resources\Public\ShotResource;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Decisions\DecisionQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ViewController
{
    public function view(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::VIEW, $shot);

        $shot->setRelation('project', $project);

        return Inertia::render('shots/view', [
            'project' => fn() => ProjectResource::make($project),
            /** @var int How many decisions wait for the director in this project. */
            'decisionsCount' => fn() => app(DecisionQueue::class)->count($project),
            'shot' => fn() => ShotResource::make($shot),
            /** @var array<int, array{value: string, label: string, description: string}> */
            'shotKinds' => fn() => ShotKind::catalogue(),
            'keyframes' => fn() => KeyframeResource::collection(
                $shot->keyframes()->with(['media', 'elements'])->get()->each->setRelation('shot', $shot)
            ),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
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
             * For a merged shot: its parts and how their clips are joined.
             *
             * @var array{transition: string, transitions: array<int, array{value: string, label: string}>, parts: array<int, array{id: string, title: string, takeaway: string, storyline: string|null, duration: int, thumbnailUrl: string|null, videoUrl: string|null, url: string}>, links: array{update: string, unmerge: string}}|null
             */
            'merge' => fn() => $this->merge($project, $shot),
            /**
             * For a part of a merged shot: the shot it was merged into.
             *
             * @var array{title: string, url: string}|null
             */
            'mergedInto' => fn() => ($merged = $shot->mergedInto()->first()) === null ? null : [
                'title' => $merged->title,
                'url' => route('public.shots.view', [$project, $merged]),
            ],
            'siblings' => fn() => ShotListItemResource::collection(
                $project->shots()->with(['project', 'keyframes.media', 'parts.keyframes.media'])->withCount(['keyframes', 'parts'])->get()
            ),
        ]);
    }

    /**
     * @return array{transition: string, transitions: array<int, array{value: string, label: string}>, parts: array<int, array{id: string, title: string, takeaway: string, storyline: string|null, duration: int, thumbnailUrl: string|null, videoUrl: string|null, url: string}>, links: array{update: string, unmerge: string}}|null
     */
    private function merge(Project $project, Shot $shot): ?array
    {
        if (! $shot->isMerged()) {
            return null;
        }

        $parts = $shot->parts()->with(['media', 'keyframes.media'])->get()->each->setRelation('project', $project);

        return [
            'transition' => $shot->merge_transition->value,
            'transitions' => $this->transitions(),
            'parts' => $parts->map(fn(Shot $part) => [
                'id' => $part->sqid,
                'title' => $part->title,
                'takeaway' => (string) $part->takeaway,
                'storyline' => $part->chosen_storyline['storyline'] ?? null,
                'duration' => $part->durationInSeconds(),
                'thumbnailUrl' => ShotListItemResource::make($part)->resolve()['thumbnailUrl'],
                'videoUrl' => ($video = $part->video()) === null ? null : URL::temporarySignedRoute('public.media.view', now()->addHours(2), ['media' => $video]),
                'url' => route('public.shots.view', [$project, $part]),
            ])->values()->all(),
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
