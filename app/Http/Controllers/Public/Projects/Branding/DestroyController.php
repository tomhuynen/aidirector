<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Branding;

use App\Models\Media;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    /**
     * Remove a logo from the branding. Pictures already drawn with it keep it.
     */
    public function destroy(Project $project, Media $logo)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        abort_unless($logo->collection_name === Project::LOGOS && $logo->model()->is($project), 404);

        $logo->delete();

        return back();
    }
}
