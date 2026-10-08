<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Media;

use App\Models\Media;
use App\Support\Media\LocalMediaFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a media file or one of its conversions. The route is signed, so
 * holding a link from a resource is the authorisation; the tenant disks are
 * private and have no URLs of their own. Media on a cloud disk is handed
 * over as a short-lived presigned link to the file in storage.
 */
class ViewController
{
    /**
     * Minutes a presigned link stays valid, and the browser may keep the redirect to it.
     */
    private const int PRESIGNED_MINUTES = 60;

    /**
     * With a signed `download` file name the file is saved under that name instead of shown.
     */
    public function view(Request $request, Media $media, string $conversion = ''): BinaryFileResponse|RedirectResponse
    {
        abort_unless($conversion === '' || $media->hasGeneratedConversion($conversion), 404);

        $downloadAs = filled($name = $request->query('download')) ? basename((string) $name) : null;

        if (! LocalMediaFiles::isLocalDisk($media->disk)) {
            $url = $media->getTemporaryUrl(now()->addMinutes(self::PRESIGNED_MINUTES), $conversion, array_filter([
                'ResponseContentDisposition' => $downloadAs === null ? null : sprintf('attachment; filename="%s"', str_replace('"', '', $downloadAs)),
            ]));

            return redirect()->away($url)->header('Cache-Control', 'private, max-age=' . (self::PRESIGNED_MINUTES - 5) * 60);
        }

        if ($downloadAs !== null) {
            return response()->download($media->getPath($conversion), $downloadAs);
        }

        return response()->file($media->getPath($conversion), [
            'Cache-Control' => 'private, max-age=7200',
        ]);
    }
}
