<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots;

use App\Enums\ShotStatus;
use App\Http\Requests\Public\SequenceRepairRequest;
use App\Jobs\RepairSequenceShot;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Renders one part of a sequence clip again, between that part's own first
 * and last frame, and puts it back in place.
 */
class RepairController
{
    public function store(SequenceRepairRequest $request, Project $project, Shot $shot, Shot $part): RedirectResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if (! $shot->isMerged() || ! $shot->parts()->get()->every(fn(Shot $candidate) => $candidate->rendersInSequence())) {
            abort(404);
        }

        if ($shot->status !== ShotStatus::VIDEO_READY || $shot->video() === null) {
            throw ValidationException::withMessages(['instruction' => __('Wait until the sequence has its video.')]);
        }

        $shot->forceFill(['status' => ShotStatus::VIDEO_PENDING, 'video_error' => null])->save();

        RepairSequenceShot::dispatch($shot, $part, (string) $request->validated('instruction'));

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
