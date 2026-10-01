<?php

declare(strict_types=1);

namespace App\Support\Elements;

use App\Models\Media;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * What the director's uploaded photos show, as text the chat and the
 * suggestion writer can reason about: each photo's caption and the people,
 * places and objects found in it by the background analysis.
 */
class PhotoInventory
{
    /**
     * The custom property holding a photo's analysis.
     */
    public const PROPERTY = 'inventory';

    /**
     * The project's content photos in upload order, numbered from 1.
     *
     * @return Collection<int, Media>
     */
    public static function photos(Project $project): Collection
    {
        return Media::query()
            ->where('model_type', $project->getMorphClass())
            ->where('model_id', $project->getKey())
            ->where('collection_name', Project::CONTENT_REFERENCES)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * One line per photo with its number, caption and what was found in it.
     * Photos still being analysed say so.
     */
    public static function describe(Project $project): string
    {
        $photos = self::photos($project);

        if ($photos->isEmpty()) {
            return 'No photos were uploaded.';
        }

        return $photos->map(function (Media $media, int $index) {
            $caption = $media->getCustomProperty(Project::CAPTION) ?? 'no caption';
            $items = $media->getCustomProperty(self::PROPERTY);

            $found = match (true) {
                $items === null => 'still being analysed',
                $items === [] => 'nothing specific found',
                default => collect($items)->map(fn(array $item) => "{$item['type']}: {$item['name']} ({$item['description']})")->join('; '),
            };

            return 'Photo ' . ($index + 1) . ": {$caption} Found: {$found}.";
        })->join("\n");
    }
}
