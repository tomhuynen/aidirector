<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Http\Requests\Public\ElementVersionRequest;
use App\Models\Element;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VersionController
{
    /**
     * Make one of the element's earlier images the chosen one again.
     */
    public function store(ElementVersionRequest $request, Project $project, Element $element)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $version = $element->references()->firstWhere('id', $request->integer('version'))
            ?? throw ValidationException::withMessages(['version' => __('That version does not belong to this element.')]);

        $element->forceFill(['reference_id' => $version->id])->save();

        return redirect()->route('public.projects.elements.view', [$project, $element]);
    }
}
