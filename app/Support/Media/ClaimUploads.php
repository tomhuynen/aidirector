<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Media;
use App\Models\Upload;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;

/**
 * Moves staging uploads into a media collection on their final owner and
 * removes the staging rows. Any model with media can claim uploads.
 */
class ClaimUploads
{
    /**
     * @param  iterable<Upload>  $uploads
     * @return Collection<int, Media>
     */
    public function toCollection(HasMedia $model, iterable $uploads, string $collection): Collection
    {
        return Collection::wrap($uploads)->map(function (Upload $upload) use ($model, $collection): Media {
            /** @var Media $media */
            $media = $model
                ->addMediaFromDisk((string) $upload->path, $upload->disk->value)
                ->usingName(pathinfo($upload->name, PATHINFO_FILENAME))
                ->usingFileName($upload->name)
                ->toMediaCollection($collection);

            $upload->delete();

            return $media;
        });
    }
}
