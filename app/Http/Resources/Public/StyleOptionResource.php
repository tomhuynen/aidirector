<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StyleOption */
class StyleOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $render = $this->render();
        $style = $this->style();
        $project = $this->relationLoaded('project') ? $this->project : Project::query()->findOrFail($this->project_id);

        return [
            'id' => $this->sqid,
            /** @var int */
            'round' => $this->round,
            /** @var int */
            'position' => $this->position,
            'name' => $style['name'],
            'look' => $style['look'],
            'medium' => $style['medium'],
            'mood' => $style['mood'],
            'palette' => $style['palette'],
            'lighting' => $style['lighting'],
            'status' => $this->status,
            /** @var string|null */
            'error' => $this->error,
            /** @var bool */
            'pinned' => $this->isPinned(),
            /** @var string|null */
            'thumbnailUrl' => $render?->signedUrl(StyleOption::THUMBNAIL),
            /** @var string|null */
            'imageUrl' => $render?->signedUrl(),
            'links' => [
                'more' => route('public.projects.style.round', ['project' => $project, 'parent' => $this->resource]),
                'pin' => route('public.projects.style.pin', ['project' => $project, 'styleOption' => $this->resource]),
            ],
        ];
    }
}
