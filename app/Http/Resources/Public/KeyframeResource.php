<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Keyframe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Keyframe */
class KeyframeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            /** @var int */
            'position' => $this->position,
            'title' => $this->title,
            'description' => $this->description,
            /** @var string|null */
            'prompt' => $this->prompt,
            /** @var string|null */
            'imageUrl' => $this->getFirstMediaUrl(Keyframe::RENDERS) ?: null,
            /** @var string|null */
            'thumbnailUrl' => $this->getFirstMediaUrl(Keyframe::RENDERS, Keyframe::THUMBNAIL) ?: null,
        ];
    }
}
