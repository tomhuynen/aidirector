<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Upload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The upload referenced by the attribute is no larger than the given size.
 * Missing uploads are left to UploadExists.
 */
class UploadMaxSize implements ValidationRule
{
    public function __construct(private readonly int $maxBytes) {}

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || ! is_string($value)) {
            return;
        }

        $upload = Upload::query()->whereSqid($value)->first();

        if ($upload !== null && $upload->size > $this->maxBytes) {
            $fail(__('The :attribute must not be greater than :max kilobytes.', [
                'max' => (int) ceil($this->maxBytes / 1024),
            ]));
        }
    }
}
