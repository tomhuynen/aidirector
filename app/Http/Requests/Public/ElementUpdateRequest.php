<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\IncludesElements;
use App\Support\Elements\ElementSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ElementUpdateRequest extends FormRequest
{
    use IncludesElements;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            /** For a person: the voice they speak with as a presenter; empty to judge it from their picture. */
            'voice' => ['nullable', Rule::in(ElementSettings::VOICES)],
            'change' => ['nullable', 'string', 'max:500'],
            ...$this->includesRules(),
        ];
    }
}
