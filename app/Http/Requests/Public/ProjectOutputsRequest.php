<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Support\Video\VideoFormats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectOutputsRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'outputs' => ['required', 'array', 'min:1'],
            'outputs.*.aspectRatio' => ['required', 'string', Rule::in(VideoFormats::aspectRatios())],
            'outputs.*.resolution' => ['required', 'string', Rule::in(VideoFormats::resolutions())],
        ];
    }
}
