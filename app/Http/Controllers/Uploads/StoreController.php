<?php

declare(strict_types=1);

namespace App\Http\Controllers\Uploads;

use App\Http\Requests\Uploads\StoreRequest;
use App\Http\Resources\UploadResource;
use App\Models\Upload;

class StoreController
{
    public function store(StoreRequest $request): UploadResource
    {
        $upload = Upload::fromFile($request->file('file'));

        return UploadResource::make($upload);
    }
}
