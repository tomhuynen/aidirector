<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Keyframe;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Keyframe */
class KeyframeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $render = $this->render();
        $rendered = $render !== null;

        return [
            'id' => $this->sqid,
            /** @var int */
            'position' => $this->position,
            'title' => $this->title,
            /**
             * How the keyframe is staged, in the fields the director edits: the short line, the spot and per person of the cast where they stand, which way they face, what they look at, what they hold in which hand and what they do.
             *
             * @var array{summary: string, place: string, cast: array<int, array{name: string, stands: string, faces: string, looks_at: string, holds: string, hand: string, doing: string}>}
             */
            'staging' => $this->staged()->toArray(),
            /** What the models get, built from the staging. */
            'description' => $this->description,
            /** The one fact a viewer must be able to check at a glance; sent as the last sentence of the description. */
            'spatial' => $this->spatial,
            /**
             * A copy the director has not described yet: it repeats another keyframe's text, so the check and review skip it.
             *
             * @var bool
             */
            'needsDescription' => $this->shot->plannedKeyframeIsCopy($this->position),
            /** @var string|null */
            'prompt' => $this->prompt,
            /**
             * Where the keyframe is in being drawn: it waits for the keyframe it is drawn from, is drawn, is checked, or is ready.
             *
             * @var 'planned'|'waiting'|'drawing'|'checking'|'ready'|'failed'
             */
            'status' => $this->status->value,
            /** @var string|null */
            'renderError' => $this->render_error,
            /** @var array<int, string> */
            'elements' => $this->whenLoaded('elements', fn() => $this->elements->pluck('name')->values()->all(), []),
            /**
             * Where the people and objects stand in the image, to pick one and move it: boxes as top, left, bottom and right on a 0 to 1000 grid.
             *
             * @var array<int, array{name: string, box: array{0: int, 1: int, 2: int, 3: int}}>
             */
            'layout' => $rendered ? array_map(fn(array $member) => ['name' => $member['name'], 'box' => $member['box']], KeyframeLayout::fromArray($this->layout)->cast) : [],
            /** @var string|null */
            'imageUrl' => $rendered ? $this->imageUrl(null, $render) : null,
            /** @var string|null */
            'thumbnailUrl' => $rendered ? $this->imageUrl(Keyframe::THUMBNAIL, $render) : null,
            /** @var array<int, array{id: int, chosen: bool, imageUrl: string, thumbnailUrl: string, request: string|null, instruction: string|null, checkIssues: array<int, string>, sent: array{model: string, prompt: string, images: array<int, string>}|null, stillness: float|null}> */
            'renders' => $this->renders()->map(fn(Media $media) => [
                'id' => $media->id,
                'chosen' => $media->id === $render?->id,
                'request' => $media->getCustomProperty(Keyframe::TWEAK_REQUEST),
                /** The adjustment came from the automatic check, not from the director. */
                'requestFromCheck' => (bool) $media->getCustomProperty(Keyframe::TWEAK_FROM_CHECK, false),
                'instruction' => $media->getCustomProperty(Keyframe::TWEAK_INSTRUCTION),
                /** What the check found wrong with this version, kept as notes. */
                'checkIssues' => array_values(array_map('strval', (array) $media->getCustomProperty(Keyframe::CHECK_ISSUES, []))),
                /** What the image model got for this version: the model, the full prompt and what each attached image was. */
                'sent' => $media->getCustomProperty(Keyframe::SENT),
                /** The share of the background that stayed in place compared with the image it was drawn on, measured in code. */
                'stillness' => $media->getCustomProperty(Keyframe::STILLNESS),
                'imageUrl' => $this->imageUrl(null, $media),
                'thumbnailUrl' => $this->imageUrl(Keyframe::THUMBNAIL, $media),
            ])->values()->all(),
            'links' => [
                'update' => route('public.shots.keyframes.update', [$this->shot->project, $this->shot, $this->resource]),
                'tweak' => route('public.shots.keyframes.tweak', [$this->shot->project, $this->shot, $this->resource]),
                /** @var string|null Reading where the people and objects stand, when the keyframe has no layout yet; only for a drawn keyframe of a scene. */
                'layout' => $this->movable($rendered) ? route('public.shots.keyframes.layout', [$this->shot->project, $this->shot, $this->resource]) : null,
                /** @var string|null Moving one of them to a new box; only for a drawn keyframe of a scene. */
                'move' => $this->movable($rendered) ? route('public.shots.keyframes.move', [$this->shot->project, $this->shot, $this->resource]) : null,
                'chooseRender' => route('public.shots.keyframes.render', [$this->shot->project, $this->shot, $this->resource]),
                'destroy' => route('public.shots.keyframes.destroy', [$this->shot->project, $this->shot, $this->resource]),
                'copy' => route('public.shots.keyframes.copy', [$this->shot->project, $this->shot, $this->resource]),
                /** @var string|null Setting how long it lasts; only in a close-up or an insert, other shots are timed by the planner. */
                'seconds' => $this->shot->kindOrScene()->timedByHand() ? route('public.shots.keyframes.seconds', [$this->shot->project, $this->shot, $this->resource]) : null,
            ],
        ];
    }

    /**
     * The render id is part of the URL so browsers never show a cached earlier version.
     */
    private function imageUrl(?string $conversion, Media $render): string
    {
        return route('public.shots.keyframes.image', array_filter([
            'project' => $this->shot->project,
            'shot' => $this->shot,
            'keyframe' => $this->resource,
            'conversion' => $conversion,
            'render' => $render->id,
        ]));
    }

    /**
     * Something can be moved in a drawn keyframe of a scene; a still of a montage or a presenter shot is drawn on its own.
     */
    private function movable(bool $rendered): bool
    {
        return $rendered && ! $this->shot->drawsStandalone();
    }
}
