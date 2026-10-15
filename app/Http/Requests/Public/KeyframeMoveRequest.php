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
            /** The name of the one of the cast that is moved, as the keyframe's layout has it. */
            'name' => ['required', 'string', 'max:120'],
            /** Its new box: top, left, bottom and right on a 0 to 1000 grid of the frame; past the edge it is cut off, wholly past it it leaves the shot. */
            'box' => ['required', 'array', 'size:4'],
            'box.*' => ['required', 'integer', 'between:-3000,4000'],
            /** Also draw the keyframes after it again, so they follow the move. */
            'following' => ['sometimes', 'boolean'],
        ];
    }
}
