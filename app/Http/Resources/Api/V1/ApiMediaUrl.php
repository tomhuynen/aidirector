<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Download links for the API: signed for three hours and only served with
 * the director's token, so a link that leaks is useless on its own.
 */
class ApiMediaUrl
{
    public static function for(?Media $media, string $conversion = '', ?string $downloadAs = null): ?string
    {
        if ($media === null) {
            return null;
        }

        return URL::temporarySignedRoute('api.v1.media.view', now()->startOfHour()->addHours(3), array_filter([
            'media' => $media,
            'conversion' => $conversion,
            'download' => $downloadAs,
        ]));
    }
}
