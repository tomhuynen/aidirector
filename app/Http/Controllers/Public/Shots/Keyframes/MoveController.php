<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Ai\KeyframePainter;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeMoveRequest;
use App\Jobs\PoseMovedPerson;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\PersonCutout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Moving a person in a keyframe by hand: the director clicks them, drags
 * them to a new spot, and the pose is redrawn for it. Only keyframes drawn
 * on a place, which is what tells the person apart from the background.
 */
class MoveController
{
    use GuardsBusyShots;

    public function __construct(
        private readonly KeyframePainter $painter,
        private readonly PersonCutout $cutout,
    ) {}

    /**
     * The shape at the clicked point: its box, a highlight and the cut-out to drag.
     */
    public function select(Request $request, Project $project, Shot $shot, Keyframe $keyframe): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $point = $request->validate(['x' => ['required', 'numeric', 'between:0,1'], 'y' => ['required', 'numeric', 'between:0,1']]);
        [$plate, $render] = $this->images($shot, $keyframe, 'x');
        $shape = $this->cutout->select($plate->getPath(), $render->getPath(), (float) $point['x'], (float) $point['y']);

        if ($shape === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person you want to move.')]);
        }

        return response()->json(['box' => $shape['box'], ...$this->cutout->previews($plate->getPath(), $render->getPath(), $shape)]);
    }

    /**
     * Put the person down at the new spot and have the pose redrawn for it.
     */
    public function store(KeyframeMoveRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'x');
        [$plate, $render] = $this->images($shot, $keyframe, 'x');

        $output = storage_path('app/tmp/moved-' . Str::random(12) . '.png');
        @mkdir(dirname($output), 0755, true);
        $box = $this->cutout->move(
            $plate->getPath(),
            $render->getPath(),
            (float) $request->validated('x'),
            (float) $request->validated('y'),
            (float) $request->validated('dx'),
            (float) $request->validated('dy'),
            (float) $request->validated('scale'),
            $output,
        );

        if ($box === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person you want to move.')]);
        }

        $moved = $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([
                Keyframe::MOVED_BY_HAND => true,
                Keyframe::SENT => ['model' => '', 'prompt' => 'Moved by hand.', 'images' => ['The place without people', 'The version before']],
            ])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $moved->id, 'rendering' => true, 'render_error' => null])->save();

        $instruction = trim((string) $request->validated('instruction'));
        PoseMovedPerson::dispatch($keyframe, implode(' ', [
            'Keep the person exactly where they stand in the second image, at the same size, with their feet on the same spot.',
            $instruction !== '' ? $instruction : "Give them the pose, gaze and gesture this describes, from where they stand now: {$keyframe->description}",
        ]), $box);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * The place the keyframe is drawn on and its current image.
     *
     * @return array{Media, Media}
     */
    private function images(Shot $shot, Keyframe $keyframe, string $field): array
    {
        $keyframe->setRelation('shot', $shot);
        $siblings = $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot);
        $plate = $this->painter->baseFor($keyframe, $siblings);
        $render = $keyframe->load('media')->render();

        if ($plate === null || $plate->collection_name !== Shot::PLATE || $render === null) {
            throw ValidationException::withMessages([$field => __('Only a keyframe drawn on a place can have a person moved.')]);
        }

        return [$plate, $render];
    }
}
