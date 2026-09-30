<?php

declare(strict_types=1);

namespace App\Http\Controllers\Uploads;

use App\Models\Upload;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an upload. The route is signed, so holding a link from the
 * upload resource is the authorisation.
 */
class ViewController
{
    public function view(Upload $upload): StreamedResponse
    {
        return $upload->response();
    }
}
