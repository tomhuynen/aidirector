<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shot
 */
class ShotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $video = $this->video();

        return [
            'id' => $this->sqid,
            'position' => $this->position,
            'title' => $this->title,
            'takeaway' => $this->takeaway,
            /** @var 'scene'|'montage'|'presenter'|null */
            'kind' => $this->kind?->value,
            'status' => $this->status->value,
            'videoReady' => $this->status === ShotStatus::VIDEO_READY && $video !== null,
            /** Changes with every new video, so a client knows when to download again. */
            'videoVersion' => $video?->id,
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'assetsUrl' => route('api.v1.projects.shots.assets', [$this->project, $this->resource]),
        ];
    }
}
