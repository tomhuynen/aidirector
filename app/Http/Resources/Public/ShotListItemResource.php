<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact shape the editor's shot list needs.
 *
 * @mixin Shot
 */
class ShotListItemResource extends JsonResource
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
            'status' => $this->status,
            'statusLabel' => $this->status->description(),
            /** @var int */
            'duration' => $this->duration ?? $this->project->default_duration,
            /** @var int */
            'keyframesCount' => $this->keyframes_count ?? 0,
            'url' => route('public.shots.view', [$this->project, $this->resource]),
        ];
    }
}
