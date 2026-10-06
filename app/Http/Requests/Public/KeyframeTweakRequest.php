<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class KeyframeTweakRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:500'],
            /** Have the text model make the request precise before it goes to the image model. */
            'rewrite' => ['sometimes', 'boolean'],
        ];
    }
}
