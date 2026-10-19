<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The director's answer to a sequence of shots the scene director laid out: make the shots with the cast and sets ticked, or not.
 */
class PlanSequenceRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The number of the turn in the conversation that laid out the sequence. */
            'turn' => ['required', 'integer', 'min:0'],
            /** Make the shots of the sequence, or leave the plan as it is. */
            'accept' => ['required', 'boolean'],
            /** The ids of the cast and sets ticked for the sequence. */
            'cast' => ['sometimes', 'array'],
            'cast.*' => ['string'],
        ];
    }
}
