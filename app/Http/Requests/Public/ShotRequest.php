<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShotRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'subject' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'string', 'max:1000'],
            'takeaway' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'purposeOverride' => ['nullable', Rule::enum(ProjectPurpose::class)],
            'aspectRatioOverride' => ['nullable', Rule::enum(AspectRatio::class)],
            'duration' => ['nullable', 'integer', 'min:2', 'max:30'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shotAttributes(): array
    {
        $validated = $this->validated();

        return [
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'action' => $validated['action'],
            'takeaway' => $validated['takeaway'],
            'notes' => $validated['notes'] ?? null,
            'purpose_override' => $validated['purposeOverride'] ?? null,
            'aspect_ratio_override' => $validated['aspectRatioOverride'] ?? null,
            'duration' => $validated['duration'] ?? null,
        ];
    }
}
