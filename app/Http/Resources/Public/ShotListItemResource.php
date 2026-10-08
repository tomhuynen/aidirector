<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            'duration' => $this->durationInSeconds(),
            /** @var int */
            'keyframesCount' => $this->keyframes_count ?? 0,
            /**
             * How many shots this one was merged from; 0 for an ordinary shot.
             *
             * @var int
             */
            'partsCount' => $this->parts_count ?? 0,
            /**
             * Whether storylines, keyframes or the video are being generated right now.
             *
             * @var bool
             */
            'busy' => $this->status->isWorking()
                || ($this->relationLoaded('keyframes') && $this->keyframes->contains(fn(Keyframe $keyframe) => (bool) $keyframe->rendering)),
            /** @var string|null */
            'thumbnailUrl' => $this->thumbnailUrl(),
            /**
             * The shot's video, for playing the whole sequence one shot after another.
             *
             * @var string|null
             */
            'videoUrl' => ($video = $this->resource->video()) === null ? null : URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $video]),
            /**
             * The spoken track per language, played along with the video in the sequence; a presenter's sound is in its video.
             *
             * @var array<int, array{locale: string, audioUrl: string}>
             */
            'voiceOvers' => $this->resource->isPresenter() ? [] : $this->resource->getMedia(Shot::VOICE_OVERS)
                ->filter(fn(Media $track) => $track->getCustomProperty('script') === $this->voice_over)
                ->map(fn(Media $track) => [
                    'locale' => (string) $track->getCustomProperty('locale'),
                    'audioUrl' => URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $track]),
                ])
                ->values()
                ->all(),
            /**
             * Shots planned together in one conversation share this key; the list outlines them as a group.
             *
             * @var string|null
             */
            'groupKey' => $this->group_key,
            /**
             * What the keyframes wait for, such as "the place of SH100", once drawing was asked for.
             *
             * @var string|null
             */
            'waitingFor' => $this->resource->waitsToDraw() ? $this->resource->waitsFor() : null,
            'url' => route('public.shots.view', [$this->project, $this->resource]),
        ];
    }

    /**
     * The thumbnail of the first rendered keyframe, when the relation is loaded.
     */
    private function thumbnailUrl(): ?string
    {
        // A merged shot has no keyframes of its own; its first part stands in.
        if ($this->relationLoaded('parts') && $this->parts->isNotEmpty()) {
            /** @var Shot $part */
            $part = $this->parts->first();

            return (new self($part->setRelation('project', $this->project)))->thumbnailUrl();
        }

        if (! $this->relationLoaded('keyframes')) {
            return null;
        }

        $keyframe = $this->keyframes->first(fn(Keyframe $keyframe) => $keyframe->render() !== null);

        return $keyframe === null
            ? null
            : route('public.shots.keyframes.image', [$this->project, $this->resource, $keyframe, Keyframe::THUMBNAIL, 'render' => $keyframe->render()->id]);
    }
}
