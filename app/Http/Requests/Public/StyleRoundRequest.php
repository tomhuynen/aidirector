<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Foundation\Http\FormRequest;

class StyleRoundRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The option to branch from; omitted for the first round. */
            'parent' => ['nullable', 'string'],
        ];
    }

    /**
     * The option this round branches from, if any. It must belong to the project.
     */
    public function parent(Project $project): ?StyleOption
    {
        $parent = $this->validated('parent');

        if ($parent === null) {
            return null;
        }

        return $project->styleOptions()->whereSqid($parent)->firstOrFail();
    }
}
