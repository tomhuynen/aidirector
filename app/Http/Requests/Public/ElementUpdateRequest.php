<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\IncludesElements;
use Illuminate\Foundation\Http\FormRequest;

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
            'change' => ['nullable', 'string', 'max:500'],
            ...$this->includesRules(),
        ];
    }
}
