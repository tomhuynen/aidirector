<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Rules\UploadExists;
use App\Rules\UploadIsImage;
use Illuminate\Foundation\Http\FormRequest;

class BrandingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Staged uploads: logos of the company, each named by its file name, such as "Damen". */
            'logos' => ['required', 'array', 'min:1', 'max:6'],
            'logos.*' => ['required', 'string', new UploadExists(), new UploadIsImage()],
        ];
    }
}
