<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class KeyframeRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
            /** The one fact a viewer must be able to check at a glance; sent as the last sentence of the description. */
            'spatial' => ['nullable', 'string', 'max:500'],
            // Draw the keyframe again from its description instead of adjusting the current image to the change.
            'redraw' => ['sometimes', 'boolean'],
        ];
    }
}
