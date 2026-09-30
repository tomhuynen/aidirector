<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Media;

use App\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a media file or one of its conversions. The route is signed, so
 * holding a link from a resource is the authorisation; the tenant disks are
 * private and have no URLs of their own.
 */
class ViewController
{
    public function view(Media $media, string $conversion = ''): BinaryFileResponse
    {
        abort_unless($conversion === '' || $media->hasGeneratedConversion($conversion), 404);

        return response()->file($media->getPath($conversion), [
            'Cache-Control' => 'private, max-age=7200',
        ]);
    }
}
