<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Requests\Public\ReorderShotsRequest;
use App\Models\Policies\ProjectPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReorderController
{
    public function store(ReorderShotsRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $shots = $project->shots()->get()->keyBy('sqid');
        $ordered = $request->validated('shots');

        if (count($ordered) !== $shots->count() || $shots->keys()->diff($ordered)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'shots' => __('The shot order does not match the shots in this project.'),
            ]);
        }

        collect($ordered)->each(function (string $sqid, int $index) use ($shots): void {
            /** @var Shot $shot */
            $shot = $shots->get($sqid);
            $shot->update(['position' => $index + 1]);
        });

        return redirect()->route('public.projects.view', $project);
    }
}
