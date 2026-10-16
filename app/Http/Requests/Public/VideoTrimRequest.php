<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class VideoTrimRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'numeric', 'min:0'],
            'end' => ['required', 'numeric', 'gt:start'],
        ];
    }

    public function start(): float
    {
        return round((float) $this->validated('start'), 3);
    }

    public function end(): float
    {
        return round((float) $this->validated('end'), 3);
    }
}
