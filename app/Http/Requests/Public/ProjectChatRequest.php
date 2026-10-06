<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Rules\UploadExists;
use App\Rules\UploadIsChatAttachment;
use Illuminate\Foundation\Http\FormRequest;

class ProjectChatRequest extends FormRequest
{
    /**
     * The most photos one turn can add, matching what the image model
     * accepts as references.
     */
    public const MAX_UPLOADS = 14;

    /** Long enough to paste a brief or a functional design straight into the chat. */
    public const MAX_MESSAGE = 20000;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation' => ['nullable', 'uuid'],
            'message' => ['required_without:uploads', 'nullable', 'string', 'max:' . self::MAX_MESSAGE],
            'uploads' => ['nullable', 'array', 'max:' . self::MAX_UPLOADS],
            /** @var string */
            'uploads.*' => ['string', new UploadExists(), new UploadIsChatAttachment()],
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
