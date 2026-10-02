<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class ReorderKeyframesRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keyframes' => ['required', 'array', 'min:1'],
            /** @var string */
            'keyframes.*' => ['required', 'string', 'distinct'],
        ];
    }
}
