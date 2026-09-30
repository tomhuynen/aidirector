<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Rules\UploadExists;
use App\Rules\UploadIsImage;
use Illuminate\Foundation\Http\FormRequest;

class ProjectChatRequest extends FormRequest
{
    /**
     * The most photos one turn can add, matching what the image model
     * accepts as references.
     */
    public const MAX_UPLOADS = 14;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation' => ['nullable', 'uuid'],
            'message' => ['required_without:uploads', 'nullable', 'string', 'max:2000'],
            'uploads' => ['nullable', 'array', 'max:' . self::MAX_UPLOADS],
            /** @var string */
            'uploads.*' => ['string', new UploadExists(), new UploadIsImage()],
        ];
    }

    /**
     * @return list<string>
     */
    public function uploadIds(): array
    {
        return array_values($this->validated('uploads') ?? []);
    }
}
