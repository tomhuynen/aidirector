<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
            /** @var string|null */
            'styleReferenceUrl' => $this->styleReferenceUrl(),
            /** @var string|null */
            'coverUrl' => $this->coverUrl(),
            /** @var bool */
            'voiceOver' => $this->settings->voiceOver,
            /** @var array<int, string> */
            'voiceOverLocales' => $this->settings->voiceOverLocales,
            /**
             * The cover gently animated as a seamless loop, once it is made.
             *
             * @var string|null
             */
            'coverLoopUrl' => $this->coverLoopUrl(),
            /** @var string|null */
            'website' => $this->website,
            'videoResolution' => $this->videoResolution(),
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
                'format' => route('public.projects.format', $this->resource),
                'voiceOver' => route('public.projects.voice-over', $this->resource),
                'decisions' => route('public.projects.decisions', $this->resource),
                'elementsStore' => route('public.projects.elements.store', $this->resource),
                'elementsCreate' => route('public.projects.elements.create', $this->resource),
                /** The editor opens on the first shot, or on a new shot when there are none. Only with the shots loaded. */
                'editor' => $this->when($this->resource->relationLoaded('shots'), fn() => $this->editorUrl()),
                'destroy' => route('public.projects.destroy', $this->resource),
                'shotsCreate' => route('public.shots.create', $this->resource),
                'shotsReorder' => route('public.shots.reorder', $this->resource),
                'shotsMerge' => route('public.shots.merge', $this->resource),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, ProjectPolicy::abilities()), []),
        ];
    }

    /**
     * A signed link to the cast and sets group picture, once it has been drawn.
     */
    private function coverLoopUrl(): ?string
    {
        $loop = $this->getFirstMedia(Project::COVER_LOOP);

        return $loop === null ? null : URL::temporarySignedRoute('public.media.view', now()->addHours(2), ['media' => $loop]);
    }

    private function coverUrl(): ?string
    {
        $cover = $this->getFirstMedia(Project::COVER);

        return $cover === null ? null : URL::temporarySignedRoute('public.media.view', now()->addHours(2), ['media' => $cover]);
    }

    /**
     * A signed link to the pinned style sheet, at the resized reference size when available.
     */
    private function styleReferenceUrl(): ?string
    {
        $sheet = $this->styleReference();

        if ($sheet === null) {
            return null;
        }

        return URL::temporarySignedRoute('public.media.view', now()->addHours(2), array_filter([
            'media' => $sheet,
            'conversion' => $sheet->hasGeneratedConversion(Project::REFERENCE) ? Project::REFERENCE : null,
        ]));
    }

    private function editorUrl(): string
    {
        $first = $this->resource->shots->first();

        return $first !== null
            ? route('public.shots.view', [$this->resource, $first])
            : route('public.shots.create', $this->resource);
    }
}
