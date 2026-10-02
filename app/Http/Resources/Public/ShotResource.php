<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Jobs\GenerateKeyframes;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Shot */
class ShotResource extends JsonResource
{
    use AuthorizesResource;

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
            'takeaway' => $this->takeaway,
            /** @var string|null */
            'notes' => $this->notes,
            /**
             * The ids of the cast and sets the storylines must use.
             *
             * @var array<int, string>
             */
            'preferredElements' => $this->preferredElementSqids(),
            'status' => $this->status,
            'statusLabel' => $this->status->description(),
            /** @var string|null */
            'purposeOverride' => $this->purpose_override,
            /** @var string|null */
            'aspectRatioOverride' => $this->aspect_ratio_override,
            /** @var int|null */
            'duration' => $this->duration,
            /** @var array<int, array{title: string, storyline: string}>|null */
            'storylineOptions' => $this->storyline_options,
            /** @var array{title: string, storyline: string}|null */
            'chosenStoryline' => $this->chosen_storyline,
            /** @var array{keyframes: array<int, array{title: string, description: string, prompt?: string}>}|null */
            'storyline' => $this->storyline,
            /** @var string|null */
            'storylineError' => $this->storyline_error,
            /** @var int */
            'firstKeyframeOptions' => GenerateKeyframes::optionCount(),
            /** @var int */
            'maxKeyframes' => (int) config('pipeline.keyframes.max'),
            'videoResolution' => $this->videoResolution(),
            /** @var array<int, string> */
            'videoResolutions' => config('pipeline.video.resolutions'),
            /** @var string|null */
            'videoPrompt' => $this->video_prompt,
            /** @var string|null */
            'videoError' => $this->video_error,
            /** @var string|null */
            'videoUrl' => $this->mediaUrl($this->video()),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'links' => $this->when($this->resource->exists, fn() => [
                'view' => route('public.shots.view', [$this->project, $this->resource]),
                'update' => route('public.shots.update', [$this->project, $this->resource]),
                'destroy' => route('public.shots.destroy', [$this->project, $this->resource]),
                'storylineSuggest' => route('public.shots.storyline.suggest', [$this->project, $this->resource]),
                'storylineChoose' => route('public.shots.storyline.choose', [$this->project, $this->resource]),
                'storylineGenerate' => route('public.shots.storyline.generate', [$this->project, $this->resource]),
                'storylineReopen' => route('public.shots.storyline.reopen', [$this->project, $this->resource]),
                'keyframesGenerate' => route('public.shots.keyframes.generate', [$this->project, $this->resource]),
                'keyframesStore' => route('public.shots.keyframes.store', [$this->project, $this->resource]),
                'firstKeyframeChoose' => route('public.shots.keyframes.first.choose', [$this->project, $this->resource]),
                'firstKeyframeMore' => route('public.shots.keyframes.first.more', [$this->project, $this->resource]),
                'videoGenerate' => route('public.shots.video.generate', [$this->project, $this->resource]),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, ShotPolicy::abilities(ShotPolicy::CREATE)), []),
        ];
    }

    /**
     * A signed link to a private media file. The expiry is rounded to the hour
     * so the link stays the same while the page polls and the browser can cache it.
     */
    private function mediaUrl(?Media $media): ?string
    {
        if ($media === null) {
            return null;
        }

        return URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $media]);
    }

    /**
     * @return array<int, string>
     */
    private function preferredElementSqids(): array
    {
        if (blank($this->preferred_elements) || ! $this->resource->exists) {
            return [];
        }

        return $this->preferredElements()->map(fn(Element $element) => $element->sqid)->values()->all();
    }
}
