<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Download links for the API: presigned for an hour on the media disk, so
 * another application fetches the file straight from storage without a token.
 */
class ApiMediaUrl
{
    public static function for(?Media $media, string $conversion = '', ?string $downloadAs = null): ?string
    {
        if ($media === null) {
            return null;
        }

        return $media->getTemporaryUrl(now()->addHour(), $conversion, array_filter([
            'ResponseContentDisposition' => $downloadAs === null ? null : sprintf('attachment; filename="%s"', str_replace('"', '', $downloadAs)),
        ]));
    }
}
