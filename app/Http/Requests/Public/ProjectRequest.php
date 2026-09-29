<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'purpose' => ['required', Rule::enum(ProjectPurpose::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'aspectRatio' => ['required', Rule::enum(AspectRatio::class)],
            'defaultDuration' => ['required', 'integer', 'min:2', 'max:30'],
            'style' => ['required', 'array'],
            'style.look' => ['nullable', 'string', 'max:500'],
            'style.palette' => ['nullable', 'string', 'max:500'],
            'style.medium' => ['nullable', 'string', 'max:200'],
            'style.mood' => ['nullable', 'string', 'max:200'],
            'style.references' => ['nullable', 'array', 'max:10'],
            /** @var string */
            'style.references.*' => ['string', 'url', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $validated = $this->validated();

        return [
            'title' => $validated['title'],
            'purpose' => $validated['purpose'],
            'description' => $validated['description'] ?? null,
            'aspect_ratio' => $validated['aspectRatio'],
            'default_duration' => $validated['defaultDuration'],
            'style' => [
                'look' => $validated['style']['look'] ?? null,
                'palette' => $validated['style']['palette'] ?? null,
                'medium' => $validated['style']['medium'] ?? null,
                'mood' => $validated['style']['mood'] ?? null,
                'references' => array_values($validated['style']['references'] ?? []),
            ],
        ];
    }
}
