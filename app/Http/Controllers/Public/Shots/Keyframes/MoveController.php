<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Enums\KeyframeStatus;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeMoveRequest;
use App\Jobs\MoveCastMember;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveController
{
    use GuardsBusyShots;

    /**
     * Move one of the cast to the box the director dragged them to. The
     * keyframe is drawn again with only them moved; the keyframes after it
     * are drawn again too when the director asks for that.
     */
    public function store(KeyframeMoveRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'move');

        $name = (string) $request->validated('name');
        /** @var array{0: int, 1: int, 2: int, 3: int} $box */
        $box = array_map('intval', array_values((array) $request->validated('box')));

        if ($keyframe->render() === null || KeyframeLayout::fromArray($keyframe->layout)->member($name) === null) {
            throw ValidationException::withMessages(['move' => __('Pick someone or something in the keyframe and drag it to its new spot.')]);
        }

        $keyframe->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => $keyframe->draw_version + 1, 'render_error' => null])->save();

        MoveCastMember::dispatch($keyframe, $keyframe->draw_version, $name, $box, $request->boolean('following'));

        return redirect()->route('public.shots.view', [$project, $shot]);
    }
}
