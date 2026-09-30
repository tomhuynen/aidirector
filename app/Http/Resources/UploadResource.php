<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/** @mixin \App\Models\Upload */
class UploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'name' => $this->name,
            'extension' => $this->extension(),
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            'isImage' => $this->isImage(),
            'url' => $this->viewUrl(),
        ];
    }

    /**
     * A short-lived signed link to the file, served by whichever frontend
     * (admin or public) the current request came through.
     */
    private function viewUrl(): string
    {
        $name = Route::is('admin.*') ? 'admin.uploads.view' : 'public.uploads.view';

        return URL::temporarySignedRoute($name, now()->addMinutes(15), ['upload' => $this->resource]);
    }
}
