<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Models\Policies\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    use AuthorizesResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'title' => $this->title,
            'purpose' => $this->purpose,
            'purposeLabel' => $this->purpose->description(),
            /** @var string|null */
            'description' => $this->description,
            /** @var array{look: string|null, palette: string|null, medium: string|null, mood: string|null, references: array<int, string>} */
            'style' => [
                'look' => $this->style['look'] ?? null,
                'palette' => $this->style['palette'] ?? null,
                'medium' => $this->style['medium'] ?? null,
                'mood' => $this->style['mood'] ?? null,
                'references' => $this->style['references'] ?? [],
            ],
            'aspectRatio' => $this->aspect_ratio,
            /** @var int */
            'defaultDuration' => $this->default_duration,
            'shotsCount' => $this->whenCounted('shots'),
            /** @var string|null */
            'archivedAt' => $this->archived_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'links' => $this->when($this->resource->exists, fn() => [
                'view' => route('public.projects.view', $this->resource),
                'update' => route('public.projects.update', $this->resource),
                'destroy' => route('public.projects.destroy', $this->resource),
                'shotsCreate' => route('public.shots.create', $this->resource),
                'shotsReorder' => route('public.shots.reorder', $this->resource),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, ProjectPolicy::abilities()), []),
        ];
    }
}
