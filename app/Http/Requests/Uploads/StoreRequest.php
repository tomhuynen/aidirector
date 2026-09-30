<?php

declare(strict_types=1);

namespace App\Http\Requests\Uploads;

use App\Models\Policies\UploadPolicy;
use App\Models\Upload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

class StoreRequest extends FormRequest
{
    /**
     * A signed-in user needs the upload ability; anonymous flows use a
     * signed URL instead.
     */
    public function authorize(): bool
    {
        if ($this->user() !== null) {
            return Gate::allows(UploadPolicy::STORE, Upload::class);
        }

        return $this->hasValidSignature();
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxKilobytes = intdiv((int) Config::get('uploads.max_file_size'), 1024);
        $mimeTypes = implode(',', Config::get('uploads.mime_types'));

        return [
            'file' => ['required', 'file', "max:{$maxKilobytes}", "mimetypes:{$mimeTypes}"],
        ];
    }
}
