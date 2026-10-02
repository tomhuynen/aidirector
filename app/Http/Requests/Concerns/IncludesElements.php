<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Element;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Validator;

/**
 * Lets a request name other elements of the project to draw into an
 * element, such as an object a person holds.
 */
trait IncludesElements
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function includesRules(): array
    {
        return [
            /** Ids of the project's elements to draw into this one, such as an object a person holds. */
            'includes' => ['nullable', 'array', 'max:3'],
            'includes.*' => ['string', 'distinct'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = $this->array('includes');

                if ($ids !== [] && $validator->errors()->isEmpty() && $this->included()->count() !== count($ids)) {
                    $validator->errors()->add('includes', __('One of the chosen elements is no longer in this project.'));
                }
            },
        ];
    }

    /**
     * The project's elements to draw in.
     *
     * @return Collection<int, Element>
     */
    public function included(): Collection
    {
        $ids = array_values(array_filter($this->array('includes'), 'is_string'));
        $project = $this->route('project');

        if ($ids === [] || ! $project instanceof Project) {
            return new Collection();
        }

        return $project->elements()->whereSqidIn('id', $ids)->get();
    }
}
