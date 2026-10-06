<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Element;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shot brief: the takeaway, optional context, and optionally the cast and
 * sets the shot must use. The planner drafts the storyline and keyframes, or
 * the director writes the keyframes themselves.
 */
class ShotRequest extends FormRequest
{
    /** @var Collection<int, Element>|null */
    private ?Collection $preferred = null;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'takeaway' => ['required', 'string', 'min:2', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /** The ids of the project's elements the storylines must use. */
            'preferredElements' => ['nullable', 'array', 'max:12'],
            'preferredElements.*' => ['string', 'distinct'],
            'purposeOverride' => ['nullable', Rule::enum(ProjectPurpose::class)],
            'aspectRatioOverride' => ['nullable', Rule::enum(AspectRatio::class)],
            'duration' => ['nullable', 'integer', 'min:2', 'max:30'],
            /** Skip the planner: the director writes the keyframes themselves. */
            'manual' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = $this->array('preferredElements');

                if ($ids === [] || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->preferred()->count() !== count($ids)) {
                    $validator->errors()->add('preferredElements', __('One of the chosen elements is no longer in this project.'));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shotAttributes(): array
    {
        $validated = $this->validated();

        return [
            'title' => Str::limit($validated['takeaway'], 80),
            'subject' => null,
            'action' => null,
            'takeaway' => $validated['takeaway'],
            'notes' => $validated['notes'] ?? null,
            'preferred_elements' => $this->preferred()->modelKeys(),
            'purpose_override' => $validated['purposeOverride'] ?? null,
            'aspect_ratio_override' => $validated['aspectRatioOverride'] ?? null,
            'duration' => $validated['duration'] ?? null,
        ];
    }

    /**
     * @return Collection<int, Element>
     */
    private function preferred(): Collection
    {
        if ($this->preferred !== null) {
            return $this->preferred;
        }

        $ids = array_filter($this->array('preferredElements'), 'is_string');
        $project = $this->route('project');

        if ($ids === [] || ! $project instanceof Project) {
            return $this->preferred = new Collection();
        }

        return $this->preferred = $project->elements()->whereSqidIn('id', array_values($ids))->get();
    }
}
