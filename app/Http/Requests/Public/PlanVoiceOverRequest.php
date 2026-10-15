<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The director's answer to a voice-over the plan director suggested: use it, or not.
 */
class PlanVoiceOverRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The number of the turn in the conversation that suggested the voice-over. */
            'turn' => ['required', 'integer', 'min:0'],
            /** Use the suggested voice-over, or keep the one there is. */
            'accept' => ['required', 'boolean'],
        ];
    }
}
