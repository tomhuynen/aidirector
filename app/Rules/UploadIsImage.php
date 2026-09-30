<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Upload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The upload referenced by the attribute is an image. Missing uploads are
 * left to UploadExists.
 */
class UploadIsImage implements ValidationRule
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

        if ($upload !== null && ! $upload->isImage()) {
            $fail(__('The :attribute must be an image.'));
        }
    }
}
