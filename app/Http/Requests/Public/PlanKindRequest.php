<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ShotKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanKindRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The kind the plan is rewritten for. */
            'kind' => ['required', Rule::enum(ShotKind::class)],
        ];
    }
}
