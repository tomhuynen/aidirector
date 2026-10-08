<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'title' => $this->title,
            /** @var int */
            'shotsCount' => (int) $this->shots_count,
            'archived' => $this->archived_at !== null,
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'shotsUrl' => route('api.v1.projects.shots.index', $this->resource),
        ];
    }
}
