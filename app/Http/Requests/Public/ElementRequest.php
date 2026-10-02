<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ElementType;
use App\Http\Requests\Concerns\IncludesElements;
use App\Rules\UploadExists;
use App\Rules\UploadIsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ElementRequest extends FormRequest
{
    use IncludesElements;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ElementType::class)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            /** A staged upload: a photo of the real thing to draw the picture from. */
            'photo' => ['nullable', 'string', new UploadExists(), new UploadIsImage()],
            ...$this->includesRules(),
        ];
    }
}
