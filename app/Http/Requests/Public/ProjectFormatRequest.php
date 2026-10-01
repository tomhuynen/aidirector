<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\AspectRatio;
use App\Support\Video\VideoFormats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectFormatRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'aspectRatio' => ['required', Rule::enum(AspectRatio::class)],
            'resolution' => ['required', 'string', Rule::in(VideoFormats::resolutions())],
        ];
    }
}
