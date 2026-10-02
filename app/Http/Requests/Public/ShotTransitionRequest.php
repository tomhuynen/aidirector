<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ShotTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShotTransitionRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transition' => ['required', Rule::enum(ShotTransition::class)],
        ];
    }

    public function transition(): ShotTransition
    {
        return ShotTransition::from((string) $this->validated('transition'));
    }
}
