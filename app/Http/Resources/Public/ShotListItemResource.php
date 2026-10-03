<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Keyframe;
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
