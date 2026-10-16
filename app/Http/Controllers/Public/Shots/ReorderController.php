<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Http\Requests\Public\ReorderShotsRequest;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReorderController
{
    /**
     * Puts the shots in the order and the groups the director dragged them
     * into. A group keeps the key one of its shots had, so the shots planned
     * together stay known as one sequence; a shot on its own has none.
     */
    public function store(ReorderShotsRequest $request, Project $project)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $shots = $project->shots()->get()->keyBy('sqid');
        $groups = $request->groups();
        $ordered = array_merge(...$groups);

        if (count($ordered) !== $shots->count() || count(array_unique($ordered)) !== count($ordered) || $shots->keys()->diff($ordered)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'groups' => __('The shot order does not match the shots in this project.'),
            ]);
        }

        DB::connection($project->getConnectionName())->transaction(function () use ($groups, $shots): void {
            $position = 0;
            $taken = [];

            foreach ($groups as $group) {
                $members = array_map(fn(string $sqid): Shot => $shots->get($sqid), $group);
                $key = null;

                if (count($members) > 1) {
                    $key = collect($members)->pluck('group_key')->filter()->first(fn(string $existing) => ! in_array($existing, $taken, true)) ?? (string) Str::ulid();
                    $taken[] = $key;
                }

                foreach ($members as $shot) {
                    $shot->forceFill(['position' => ++$position, 'group_key' => $key])->save();
                }
            }
        });

        return back();
    }
}
