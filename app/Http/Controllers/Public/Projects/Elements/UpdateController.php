<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Http\Requests\Public\ElementUpdateRequest;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class UpdateController
{
    /**
     * Save the name and description. A change request edits the picture; a
     * new description without one draws it again.
     */
    public function store(ElementUpdateRequest $request, Project $project, Element $element)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $change = trim((string) $request->validated('change'));
        $redraw = $change === '' && trim($request->validated('description')) !== trim($element->description);

        $element->fill($request->safe()->only(['name', 'description']));

        if ($change !== '' || $redraw || $element->reference() === null) {
            $element->forceFill(['rendering' => true, 'render_error' => null]);
            $element->save();

            UpdateElementImage::dispatch($element, $change !== '' ? $change : null);
        } else {
            $element->save();
        }

        return redirect()->route('public.projects.elements.view', [$project, $element]);
    }
}
