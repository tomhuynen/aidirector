<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
            'thumbnailUrl' => $render === null ? null : $this->mediaUrl($render->sqid, StyleOption::THUMBNAIL),
            /** @var string|null */
            'imageUrl' => $render === null ? null : $this->mediaUrl($render->sqid),
            'links' => [
                'more' => route('public.projects.style.round', ['project' => $project, 'parent' => $this->resource]),
                'pin' => route('public.projects.style.pin', ['project' => $project, 'styleOption' => $this->resource]),
            ],
        ];
    }

    private function mediaUrl(string $media, ?string $conversion = null): string
    {
        return URL::temporarySignedRoute('public.media.view', now()->addHours(2), array_filter(['media' => $media, 'conversion' => $conversion]));
    }
}
