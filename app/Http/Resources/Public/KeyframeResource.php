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
            /** @var string|null */
            'prompt' => $this->prompt,
            /** @var bool */
            'rendering' => $this->rendering,
            /** @var string|null */
            'renderError' => $this->render_error,
            /** @var array<int, string> */
            'elements' => $this->whenLoaded('elements', fn() => $this->elements->pluck('name')->values()->all(), []),
            /** @var string|null */
            'imageUrl' => $rendered ? $this->imageUrl(null, $render) : null,
            /** @var string|null */
            'thumbnailUrl' => $rendered ? $this->imageUrl(Keyframe::THUMBNAIL, $render) : null,
            /** @var array<int, array{id: int, chosen: bool, imageUrl: string, thumbnailUrl: string}> */
            'renders' => $this->renders()->map(fn(Media $media) => [
                'id' => $media->id,
                'chosen' => $media->id === $render?->id,
                'imageUrl' => $this->imageUrl(null, $media),
                'thumbnailUrl' => $this->imageUrl(Keyframe::THUMBNAIL, $media),
            ])->values()->all(),
            'links' => [
                'update' => route('public.shots.keyframes.update', [$this->shot->project, $this->shot, $this->resource]),
                'tweak' => route('public.shots.keyframes.tweak', [$this->shot->project, $this->shot, $this->resource]),
                'chooseRender' => route('public.shots.keyframes.render', [$this->shot->project, $this->shot, $this->resource]),
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
}
