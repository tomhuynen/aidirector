<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Upload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The attribute is the sqid of an existing upload.
 */
class UploadExists implements ValidationRule
{
    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value) || ! Upload::query()->whereSqid($value)->exists()) {
            $fail(__('The :attribute must be a valid upload.'));
        }
    }
}
