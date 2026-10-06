<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ShotTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeShotsRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shots' => ['required', 'array', 'min:2', 'max:100'],
            /** @var string */
            'shots.*' => ['required', 'string', 'distinct'],
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'transition' => ['required', Rule::enum(ShotTransition::class)],
        ];
    }

    public function transition(): ShotTransition
    {
        return ShotTransition::from((string) $this->validated('transition'));
    }
}
