<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class ReorderShotsRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The shots in order, as groups: a group of one is a shot on its own. */
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['required', 'array', 'min:1'],
            /** @var string */
            'groups.*.*' => ['required', 'string'],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function groups(): array
    {
        return array_values(array_map(fn(array $group) => array_values(array_map('strval', $group)), (array) $this->validated('groups')));
    }
}
