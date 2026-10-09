<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A message to the plan director, with what is selected once the images are drawn. The plan itself is read from the shot.
 */
class PlanDirectorRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** What the director says to the plan director. */
            'message' => ['required', 'string', 'max:2000'],
            /** The ids of the cast and sets the director just made from the chat; they stay shown with the message. */
            'made' => ['sometimes', 'array', 'max:8'],
            'made.*' => ['string'],
            /** Once the keyframes are drawn: what is selected, a keyframe. */
            'target' => ['nullable', 'string', 'in:keyframe'],
            /** The keyframe's id, when a keyframe is selected. */
            'keyframe' => ['nullable', 'string'],
        ];
    }
}
