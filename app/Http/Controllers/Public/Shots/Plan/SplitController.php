<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Jobs\GenerateStoryline;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A takeaway with two messages can be split into two shots, as the planner
 * proposes: this shot keeps the first message, a new shot right after it gets
 * the second, and both are planned again by the kind that fits them.
 */
class SplitController
{
    use ReturnsToDecisions;

    public function store(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        [$first, $second] = $this->parts($shot);

        $next = DB::connection($shot->getConnectionName())->transaction(function () use ($project, $shot, $first, $second) {
            $project->shots()->where('position', '>', $shot->position)->increment('position');

            $next = $project->allShots()->create([
                'position' => $shot->position + 1,
                'title' => Str::limit($second['takeaway'], 80),
                'takeaway' => $second['takeaway'],
                'kind' => ShotKind::from($second['kind']),
                'notes' => $shot->notes,
                'preferred_elements' => $shot->preferred_elements,
                'purpose_override' => $shot->purpose_override,
                'aspect_ratio_override' => $shot->aspect_ratio_override,
                'status' => ShotStatus::STORYLINE_PENDING,
            ]);

            $shot->forceFill([
                'title' => Str::limit($first['takeaway'], 80),
                'takeaway' => $first['takeaway'],
                'kind' => ShotKind::from($first['kind']),
                'chosen_storyline' => null,
                'storyline' => null,
                'voice_over' => null,
                'status' => ShotStatus::STORYLINE_PENDING,
                'storyline_error' => null,
            ])->save();

            return $next;
        });

        GenerateStoryline::dispatch($shot, draw: false, keepKind: true);
        GenerateStoryline::dispatch($next, draw: false, keepKind: true);

        return $this->afterAction($project, $shot);
    }

    /**
     * Keep the shot as one: the proposal is not shown again.
     */
    public function destroy(Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'split' => ['dismissed' => true]]);

        return $this->afterAction($project, $shot);
    }

    /**
     * @return array{0: array{takeaway: string, kind: string}, 1: array{takeaway: string, kind: string}}
     */
    private function parts(Shot $shot): array
    {
        $parts = (array) ($shot->storyline['split']['parts'] ?? []);

        if ($shot->status !== ShotStatus::STORYLINE_READY || $shot->keyframes()->exists() || count($parts) !== 2) {
            throw ValidationException::withMessages(['split' => __('A shot can be split while its plan waits and nothing is drawn yet.')]);
        }

        return [$parts[0], $parts[1]];
    }
}
