<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/** @mixin Element */
class ElementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reference = $this->reference();

        return [
            'id' => $this->sqid,
            'type' => $this->type,
            'typeLabel' => $this->type->description(),
            'name' => $this->name,
            'description' => $this->description,
            /** @var bool */
            'rendering' => $this->rendering,
            /**
             * Every version of the image, oldest first, with the chosen one marked.
             *
             * @var array<int, array{id: int, chosen: bool, imageUrl: string, thumbnailUrl: string, request: string|null}>
             */
            'versions' => $this->references()->map(fn(BaseMedia $media) => [
                'id' => $media->id,
                'chosen' => $media->id === $reference?->id,
                'imageUrl' => $this->signed($media),
                'thumbnailUrl' => $this->signed($media, Element::THUMBNAIL),
                'request' => $media->getCustomProperty(Element::CHANGE_REQUEST),
            ])->values()->all(),
            /** @var string|null */
            'renderError' => $this->render_error,
            'url' => route('public.projects.elements.view', [$this->project, $this->resource]),
            'versionUrl' => route('public.projects.elements.version', [$this->project, $this->resource]),
            /** @var string|null */
            'imageUrl' => $reference === null ? null : $this->signed($reference, Element::THUMBNAIL),
            /**
             * The shots it appears in, when the keyframes and their shots are loaded.
             *
             * @var array<int, array{position: int, title: string, url: string}>
             */
            'shots' => $this->whenLoaded('keyframes', fn() => $this->keyframes
                ->map(fn(Keyframe $keyframe) => $keyframe->shot)
                ->unique('id')
                ->sortBy('position')
                ->map(fn($shot) => [
                    'position' => $shot->position,
                    'title' => $shot->title,
                    'url' => route('public.shots.view', [$this->project, $shot]),
                ])
                ->values()
                ->all(), []),
        ];
    }

    /**
     * A signed link to a version, stable within the hour so a polling page keeps its cache.
     */
    private function signed(BaseMedia $media, ?string $conversion = null): string
    {
        return URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), array_filter([
            'media' => $media,
            'conversion' => $conversion !== null && $media->hasGeneratedConversion($conversion) ? $conversion : null,
        ]));
    }
}
