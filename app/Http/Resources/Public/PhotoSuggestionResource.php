<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\PhotoSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhotoSuggestion */
class PhotoSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'thumbnailUrl' => (string) $this->thumbnail_url,
            /** @var string|null */
            'title' => $this->title,
            /** @var string|null */
            'domain' => $this->domain,
            /** @var string|null */
            'sourceUrl' => $this->source_url,
            /** @var int|null */
            'width' => $this->width,
            /** @var int|null */
            'height' => $this->height,
            'fromWebsite' => (bool) $this->from_website,
            'picked' => $this->isPicked(),
        ];
    }
}
