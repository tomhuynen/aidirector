<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Media;

use App\Models\Media;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a media file or one of its conversions. The route is signed, so
 * holding a link from a resource is the authorisation; the tenant disks are
 * private and have no URLs of their own.
 */
class ViewController
{
    /**
     * With a signed `download` file name the file is saved under that name instead of shown.
     */
    public function view(Request $request, Media $media, string $conversion = ''): BinaryFileResponse
    {
        abort_unless($conversion === '' || $media->hasGeneratedConversion($conversion), 404);

        if (filled($name = $request->query('download'))) {
            return response()->download($media->getPath($conversion), basename((string) $name));
        }

        return response()->file($media->getPath($conversion), [
            'Cache-Control' => 'private, max-age=7200',
        ]);
    }
}
