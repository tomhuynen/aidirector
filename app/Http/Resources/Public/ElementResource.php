<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
            /** @var string|null */
            'imageUrl' => $reference === null ? null : URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), array_filter([
                'media' => $reference,
                'conversion' => $reference->hasGeneratedConversion(Element::THUMBNAIL) ? Element::THUMBNAIL : null,
            ])),
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
}
