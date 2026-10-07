<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Keyframe;
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
            'description' => $this->description,
            /**
             * A copy the director has not described yet: it repeats another keyframe's text, so the check and review skip it.
             *
             * @var bool
             */
            'needsDescription' => $this->shot->plannedKeyframeIsCopy($this->position),
            /** @var string|null */
            'prompt' => $this->prompt,
            /** @var bool */
            'rendering' => $this->rendering,
            /**
             * What the rendering is busy with after drawing: checking the image, or fixing what the check found.
             *
             * @var 'checking'|'fixing'|null
             */
            'renderStage' => $this->rendering ? $this->render_stage : null,
            /**
             * While a mistake is being fixed: what the check found, in plain words.
             *
             * @var string|null
             */
            'renderNote' => $this->rendering ? $this->render_note : null,
            /** @var string|null */
            'renderError' => $this->render_error,
            /** @var array<int, string> */
            'elements' => $this->whenLoaded('elements', fn() => $this->elements->pluck('name')->values()->all(), []),
            /** @var string|null */
            'imageUrl' => $rendered ? $this->imageUrl(null, $render) : null,
            /** @var string|null */
            'thumbnailUrl' => $rendered ? $this->imageUrl(Keyframe::THUMBNAIL, $render) : null,
            /** @var array<int, array{id: int, chosen: bool, imageUrl: string, thumbnailUrl: string, request: string|null, instruction: string|null, checkProblems: array<int, string>, checkIssues: array<int, string>, sent: array{model: string, prompt: string, images: array<int, string>}|null, stillness: float|null, moveWarning: string|null}> */
            'renders' => $this->renders()->map(fn(Media $media) => [
                'id' => $media->id,
                'chosen' => $media->id === $render?->id,
                'request' => $media->getCustomProperty(Keyframe::TWEAK_REQUEST),
                /** The adjustment came from the automatic check, not from the director. */
                'requestFromCheck' => (bool) $media->getCustomProperty(Keyframe::TWEAK_FROM_CHECK, false),
                'instruction' => $media->getCustomProperty(Keyframe::TWEAK_INSTRUCTION),
                'checkProblems' => (array) $media->getCustomProperty(Keyframe::CHECK_PROBLEMS, []),
                /** What the check found wrong with this version, kept as notes. */
                'checkIssues' => array_values(array_map('strval', (array) $media->getCustomProperty(Keyframe::CHECK_ISSUES, []))),
                /** What the image model got for this version: the model, the full prompt and what each attached image was. */
                'sent' => $media->getCustomProperty(Keyframe::SENT),
                /** @var string|null After a person was moved: why the image no longer fits the keyframe's point in the story. */
                'moveWarning' => $media->getCustomProperty(Keyframe::MOVE_WARNING),
                /** The share of the background that stayed in place compared with the image it was drawn on, measured in code. */
                'stillness' => $media->getCustomProperty(Keyframe::STILLNESS),
                'imageUrl' => $this->imageUrl(null, $media),
                'thumbnailUrl' => $this->imageUrl(Keyframe::THUMBNAIL, $media),
            ])->values()->all(),
            'links' => [
                'update' => route('public.shots.keyframes.update', [$this->shot->project, $this->shot, $this->resource]),
                'tweak' => route('public.shots.keyframes.tweak', [$this->shot->project, $this->shot, $this->resource]),
                /** @var string|null Moving a person by hand; only for a keyframe drawn on a chosen place. */
                'move' => $this->movable($rendered) ? route('public.shots.keyframes.move', [$this->shot->project, $this->shot, $this->resource]) : null,
                /** @var string|null */
                'moveSelect' => $this->movable($rendered) ? route('public.shots.keyframes.move.select', [$this->shot->project, $this->shot, $this->resource]) : null,
                'chooseRender' => route('public.shots.keyframes.render', [$this->shot->project, $this->shot, $this->resource]),
                'destroy' => route('public.shots.keyframes.destroy', [$this->shot->project, $this->shot, $this->resource]),
                'copy' => route('public.shots.keyframes.copy', [$this->shot->project, $this->shot, $this->resource]),
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
     * A person can be moved on a drawn keyframe of a scene that is drawn on a chosen place.
     */
    private function movable(bool $rendered): bool
    {
        return $rendered && ! $this->shot->drawsStandalone() && $this->shot->hasChosenPlate();
    }
}
