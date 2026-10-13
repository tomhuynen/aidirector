<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class KeyframeMoveRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** Where the director clicked the person, as shares of the image width and height. */
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            /** How far the person's feet move, as shares of the image width and height. */
            'dx' => ['required', 'numeric', 'between:-1,1'],
            'dy' => ['required', 'numeric', 'between:-1,1'],
            /** How much larger or smaller the person becomes around their feet: from the depth of the place at the old and new spot. */
            'scale' => ['required', 'numeric', 'between:0.3,3'],
            /** Whether it is redrawn at the new spot after the move, so it sits there with its own shadow and light. */
            'redraw' => ['sometimes', 'boolean'],
        ];
    }
}
