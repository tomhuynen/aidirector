<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The director's answer to a change the plan director proposed for one of the cast: make it, or not.
 */
class PlanChangeRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The number of the turn in the conversation that proposed the change. */
            'turn' => ['required', 'integer', 'min:0'],
            /** Make the change, or leave it. */
            'apply' => ['required', 'boolean'],
            /** Also draw the keyframes after it again, so they follow the change. */
            'following' => ['sometimes', 'boolean'],
        ];
    }
}
