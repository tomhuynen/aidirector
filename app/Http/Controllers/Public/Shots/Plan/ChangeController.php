<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\KeyframeStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\PlanChangeRequest;
use App\Jobs\ChangeCastMember;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The director's answer to a change the plan director proposed for one of
 * the cast in a keyframe. Confirmed, the keyframe is drawn again with only
 * them changed, their staging says what changed, and the keyframes after
 * it are drawn again too when the director asks for that; cancelled,
 * nothing changes.
 */
class ChangeController
{
    use GuardsBusyShots;

    public function store(PlanChangeRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $turns = array_values((array) ($shot->plan_chat ?? []));
        $index = $request->integer('turn');
        $proposed = $turns[$index]['proposed'] ?? null;

        if (! is_array($proposed) || isset($proposed['state'])) {
            throw ValidationException::withMessages(['turn' => __('This change was already answered.')]);
        }

        if ($request->boolean('apply')) {
            $keyframe = $shot->keyframes()->get()->firstWhere('sqid', (string) $proposed['keyframe']);

            if ($keyframe === null || $keyframe->render() === null || KeyframeLayout::fromArray($keyframe->layout)->member((string) $proposed['name']) === null) {
                throw ValidationException::withMessages(['turn' => __('This keyframe changed meanwhile. Select them again.')]);
            }

            $this->ensureKeyframeIdle($shot, $keyframe, 'turn');
            $staging = $keyframe->staged()->withMember((array) ($proposed['member'] ?? []));
            $keyframe->restage($staging)->save();
            $shot->updatePlannedKeyframe($keyframe->position, $staging->fields(), ['copied']);

            $keyframe->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => $keyframe->draw_version + 1, 'render_error' => null])->save();
            ChangeCastMember::dispatch($keyframe, $keyframe->draw_version, (string) $proposed['name'], (string) $proposed['change'], $request->boolean('following'));
        }

        $turns[$index]['proposed']['state'] = $request->boolean('apply') ? 'made' : 'cancelled';
        $shot->forceFill(['plan_chat' => $turns])->save();

        return response()->json(['messages' => $turns]);
    }
}
