<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class PhotoPickRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'suggestions' => ['required', 'array', 'min:1', 'max:' . ProjectChatRequest::MAX_UPLOADS],
            /** @var string */
            'suggestions.*' => ['string'],
        ];
    }

    /**
     * @return list<string>
     */
    public function suggestionIds(): array
    {
        return array_values($this->validated('suggestions'));
    }
}
