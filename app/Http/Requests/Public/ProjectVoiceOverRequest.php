<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class ProjectVoiceOverRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** @var bool */
            'enabled' => ['required', 'boolean'],
            'locales' => ['present', 'array'],
            'locales.*' => ['string', 'distinct', Rule::in((array) Config::get('pipeline.voice_over.locales'))],
        ];
    }
}
