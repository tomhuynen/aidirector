<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One decision per proposed element, in the order they were proposed:
 * add it to the cast and sets, use an existing element instead, or skip it.
 */
class ElementReviewRequest extends FormRequest
{
    public const ADD = 'add';

    public const EXISTING = 'existing';

    public const SKIP = 'skip';

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array'],
            'decisions.*.action' => ['required', 'string', 'in:' . implode(',', [self::ADD, self::EXISTING, self::SKIP])],
            'decisions.*.element' => ['nullable', 'string', 'required_if:decisions.*.action,' . self::EXISTING],
        ];
    }
}
