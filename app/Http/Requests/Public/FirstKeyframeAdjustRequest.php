<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class FirstKeyframeAdjustRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The option for keyframe 1 to adjust. */
            'render' => ['required', 'integer'],
            'instruction' => ['required', 'string', 'max:500'],
        ];
    }
}
