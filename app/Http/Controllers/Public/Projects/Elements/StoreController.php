<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Http\Requests\Public\ElementRequest;
use App\Jobs\UpdateElementImage;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class StoreController
{
    /**
     * Add a person, place or object to the cast and sets, draw its reference
     * image from the description in the project's style and open its page.
     */
    public function store(ElementRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $element = $project->elements()->create([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'rendering' => true,
        ]);

        UpdateElementImage::dispatch($element);

        return redirect()->route('public.projects.elements.view', [$project, $element]);
    }
}
