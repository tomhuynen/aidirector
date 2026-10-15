<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\IncludesElements;
use App\Models\Project;
use App\Support\Elements\ElementSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ElementUpdateRequest extends FormRequest
{
    use IncludesElements {
        after as includesAfter;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            /** For a person: the voice they speak with as a presenter; empty to judge it from their picture. */
            'voice' => ['nullable', Rule::in(ElementSettings::VOICES)],
            'change' => ['nullable', 'string', 'max:500'],
            ...$this->includesRules(),
            /** Ids of logos from the project's branding to put on it. */
            'logos' => ['nullable', 'array', 'max:' . Project::MAX_LOGOS_PER_IMAGE],
            'logos.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            ...$this->includesAfter(),
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (count($this->array('logos')) !== $this->pickedLogos()->count()) {
                    $validator->errors()->add('logos', __('One of the chosen logos is no longer in the branding.'));
                }

                // A logo can only come from the branding: asking for one without it would have the model invent it.
                $project = $this->route('project');

                if ($project instanceof Project && $project->brandNames() === [] && preg_match('/\\b(logo|logos|branding)\\b/iu', (string) $this->input('change')) === 1) {
                    $validator->errors()->add('change', __('There is no logo in the branding yet. Upload one on the project page first.'));
                }
            },
        ];
    }

    /**
     * The logos of the project's branding picked to put on it.
     *
     * @return Collection<int, Media>
     */
    public function pickedLogos(): Collection
    {
        $ids = array_values(array_filter($this->array('logos'), 'is_numeric'));
        $project = $this->route('project');

        if ($ids === [] || ! $project instanceof Project) {
            return collect();
        }

        return $project->getMedia(Project::LOGOS)->filter(fn(Media $logo) => in_array($logo->id, array_map('intval', $ids), true))->values();
    }
}
