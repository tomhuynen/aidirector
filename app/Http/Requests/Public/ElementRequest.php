<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ElementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ElementRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ElementType::class)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
        ];
    }
}
