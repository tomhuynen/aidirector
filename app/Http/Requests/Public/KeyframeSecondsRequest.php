<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

class KeyframeSecondsRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'seconds' => ['required', 'numeric', 'multiple_of:0.5', 'min:0.5', 'max:' . Config::get('pipeline.video.max_duration')],
        ];
    }

    public function seconds(): float
    {
        return (float) $this->validated('seconds');
    }
}
