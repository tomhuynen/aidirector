<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class PlanChatRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** What the director typed in the plan editor. */
            'message' => ['required', 'string', 'max:2000'],
            'storyline' => ['nullable', 'string', 'max:2000'],
            /** The shot's rules as they are in the editor, with any removed there left out. */
            'rules' => ['sometimes', 'array', 'max:20'],
            'rules.*' => ['nullable', 'string', 'max:300'],
            /** The plan as it is in the editor, in order. */
            'keyframes' => ['present', 'array', 'max:30'],
            'keyframes.*.title' => ['nullable', 'string', 'max:120'],
            'keyframes.*.description' => ['nullable', 'string', 'max:2000'],
            'keyframes.*.prompt' => ['nullable', 'string', 'max:3000'],
            'keyframes.*.mustShow' => ['nullable', 'string', 'max:500'],
            /** The keyframes to write, by position in the new order, with what each should show. */
            'targets' => ['sometimes', 'array', 'max:30'],
            'targets.*.position' => ['required', 'integer', 'min:1'],
            'targets.*.instruction' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<array{title: string, description: string, prompt: string, mustShow: string}>
     */
    public function keyframes(): array
    {
        return array_values(array_map(fn(array $keyframe) => [
            'title' => (string) ($keyframe['title'] ?? ''),
            'description' => (string) ($keyframe['description'] ?? ''),
            'prompt' => (string) ($keyframe['prompt'] ?? ''),
            'mustShow' => (string) ($keyframe['mustShow'] ?? ''),
        ], (array) $this->validated('keyframes')));
    }
}
