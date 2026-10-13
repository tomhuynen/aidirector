<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class DestroyShotsRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shots' => ['required', 'array', 'min:1', 'max:100'],
            /** @var string */
            'shots.*' => ['required', 'string', 'distinct'],
            /** The shot open in the editor, to stay on when it is not deleted. */
            'current' => ['nullable', 'string'],
        ];
    }
}
