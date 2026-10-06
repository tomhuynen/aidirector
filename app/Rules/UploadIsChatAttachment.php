<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Upload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The upload is something the intake chat can use: a photo, or a document
 * it can read (PDF or plain text). Missing uploads are left to UploadExists.
 */
class UploadIsChatAttachment implements ValidationRule
{
    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || ! is_string($value)) {
            return;
        }

        $upload = Upload::query()->whereSqid($value)->first();

        if ($upload !== null && ! $upload->isImage() && ! $upload->isDocument()) {
            $fail(__('Add photos, a PDF, an RTF or a text file.'));
        }
    }
}
