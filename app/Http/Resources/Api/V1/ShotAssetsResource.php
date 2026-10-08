<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Everything another application needs to show a shot: its video, the
 * spoken track per language and a thumbnail, as download links. A presenter
 * shot has a video per language with the voice in it instead of tracks.
 *
 * @mixin Shot
 */
class ShotAssetsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $video = $this->video();

        return [
            'id' => $this->sqid,
            'title' => $this->title,
            /** @var array{url: string, version: int, seconds: float|null}|null */
            'video' => $video === null ? null : [
                'url' => ApiMediaUrl::for($video, downloadAs: $this->fileName($video)),
                'version' => $video->id,
                'seconds' => is_numeric($seconds = $video->getCustomProperty(Shot::VIDEO_SECONDS)) ? (float) $seconds : null,
            ],
            /** @var string|null */
            'voiceOverText' => $this->voice_over,
            /**
             * The voice-over as spoken in every language, translated and shortened where needed; also for a presenter.
             *
             * @var list<array{locale: string, text: string}>
             */
            'voiceOverTexts' => $this->getMedia(Shot::VOICE_OVERS)
                ->filter(fn(Media $track) => $track->getCustomProperty('script') === $this->voice_over)
                ->map(fn(Media $track) => [
                    'locale' => (string) $track->getCustomProperty('locale'),
                    'text' => (string) ($track->getCustomProperty('text') ?? $this->voice_over),
                ])
                ->values()
                ->all(),
            /**
             * The spoken track per language, for the current voice-over text.
             *
             * @var list<array{locale: string, url: string}>
             */
            'voiceOvers' => $this->isPresenter() ? [] : $this->getMedia(Shot::VOICE_OVERS)
                ->filter(fn(Media $track) => $track->getCustomProperty('script') === $this->voice_over)
                ->map(fn(Media $track) => [
                    'locale' => (string) $track->getCustomProperty('locale'),
                    'url' => ApiMediaUrl::for($track, downloadAs: $this->fileName($track, (string) $track->getCustomProperty('locale'))),
                ])
                ->values()
                ->all(),
            /**
             * A presenter's video per language, with the voice in it.
             *
             * @var list<array{locale: string, url: string}>
             */
            'languageVideos' => ! $this->isPresenter() ? [] : $this->getMedia(Shot::PRESENTER_VIDEOS)
                ->map(fn(Media $languageVideo) => [
                    'locale' => (string) $languageVideo->getCustomProperty('locale'),
                    'url' => ApiMediaUrl::for($languageVideo, downloadAs: $this->fileName($languageVideo, (string) $languageVideo->getCustomProperty('locale'))),
                ])
                ->values()
                ->all(),
            /** @var string|null */
            'thumbnailUrl' => $this->thumbnailUrl(),
            /**
             * The keyframes in order, each with its full image; a merged shot lists those of its parts.
             *
             * @var list<array{position: int, title: string, description: string, imageUrl: string|null}>
             */
            'keyframes' => $this->keyframesInOrder()
                ->values()
                ->map(fn(Keyframe $keyframe, int $index) => [
                    'position' => $index + 1,
                    'title' => $keyframe->title,
                    'description' => $keyframe->description,
                    'imageUrl' => ($render = $keyframe->render()) === null ? null : ApiMediaUrl::for($render, downloadAs: $this->fileName($render, 'keyframe ' . ($index + 1))),
                ])
                ->all(),
        ];
    }

    /**
     * The thumbnail of the first drawn keyframe; a merged shot uses its first part.
     */
    private function thumbnailUrl(): ?string
    {
        $shot = $this->parts->first() ?? $this->resource;
        $keyframe = $shot->keyframes->first(fn(Keyframe $keyframe) => $keyframe->render() !== null);

        return $keyframe === null ? null : ApiMediaUrl::for($keyframe->render(), Keyframe::THUMBNAIL);
    }

    /**
     * @return Collection<int, Keyframe>
     */
    private function keyframesInOrder(): Collection
    {
        $shots = $this->parts->isNotEmpty() ? $this->parts : collect([$this->resource]);

        return $shots->flatMap(fn(Shot $shot) => $shot->keyframes->sortBy('position'));
    }

    private function fileName(Media $media, ?string $locale = null): string
    {
        $title = Str::of((string) $this->title)->replaceMatches('/[^\pL\pN ._-]+/u', '')->trim()->limit(80, '');

        return sprintf('SH%03d %s%s.%s', $this->position * 10, $title, $locale !== null ? " {$locale}" : '', pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'mp4');
    }
}
