<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Models\Shot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorylineChoiceRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Shot $shot */
        $shot = $this->route('shot');

        return [
            'option' => ['required', 'integer', Rule::in(array_keys($shot->storylineOptions()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'option.in' => __('Pick one of the suggested storylines.'),
        ];
    }
}
