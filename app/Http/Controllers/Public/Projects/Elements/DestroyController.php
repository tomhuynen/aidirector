<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Models\Element;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    /**
     * Remove a person, place or object from the cast and sets with its
     * pictures. Keyframes that showed it keep their images.
     */
    public function destroy(Project $project, Element $element)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $element->delete();

        return redirect()->route('public.projects.view', $project);
    }
}
