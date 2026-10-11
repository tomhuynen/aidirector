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
use App\Support\Images\PlaceDepth;
use App\Support\Media\LocalMediaFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Moving a person in a keyframe by hand: the director clicks them in the
 * image and drags them to a new spot, where they are put down at once, sized
 * by the depth of the place and hidden behind what stands in front of them.
 * Fitting their pose, gaze and gesture to the new spot is a separate step
 * the director asks for. Only keyframes drawn on a place, which is what tells
 * the person apart from the background.
 */
class MoveController
{
    use GuardsBusyShots;

    public function __construct(
        private readonly KeyframePainter $painter,
        private readonly PersonCutout $cutout,
        private readonly PlaceDepth $depth,
    ) {}

    /**
     * The shape at the clicked point: its box, a highlight, the cut-out to drag and the depth of the place, which sizes it while it is dragged.
     */
    public function select(Request $request, Project $project, Shot $shot, Keyframe $keyframe): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $point = $request->validate(['x' => ['required', 'numeric', 'between:0,1'], 'y' => ['required', 'numeric', 'between:0,1']]);
        [$plate, $render] = $this->images($shot, $keyframe, 'x');
        $files = new LocalMediaFiles();
        $cutout = $this->cutoutFor($render, $files);
        $shape = $cutout->select($files->path($plate), $files->path($render), (float) $point['x'], (float) $point['y']);

        if ($shape === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person you want to move.')]);
        }

        return response()->json([
            'box' => $shape['box'],
            ...$cutout->previews($files->path($plate), $files->path($render), $shape),
            /** @var array{width: int, height: int, values: array<int, float>}|null */
            'depth' => $this->depth->of($plate)?->forEditor(),
        ]);
    }

    /**
     * Put the person down at the new spot: the moved image is the keyframe's image straight away.
     */
    public function store(KeyframeMoveRequest $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'x');
        [$plate, $render] = $this->images($shot, $keyframe, 'x');

        $output = storage_path('app/tmp/moved-' . Str::random(12) . '.png');
        @mkdir(dirname($output), 0755, true);
        $files = new LocalMediaFiles();
        $cutout = $this->cutoutFor($render, $files);
        $box = $cutout->move(
            $files->path($plate),
            $files->path($render),
            (float) $request->validated('x'),
            (float) $request->validated('y'),
            (float) $request->validated('dx'),
            (float) $request->validated('dy'),
            (float) $request->validated('scale'),
            $output,
            $this->depth->of($plate),
        );

        if ($box === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person you want to move.')]);
        }

        $moved = $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([
                Keyframe::SENT => ['model' => '', 'prompt' => 'Moved by hand.', 'images' => ['The place without people', 'The version before']],
                Keyframe::MOVED_BOX => $box,
                ...array_filter([Keyframe::PEOPLE_MASK => ($moved = $cutout->movedPeople()) !== null ? base64_encode($moved) : null]),
            ])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $moved->id, 'render_error' => null])->save();

        // The description and the plan follow the image, then the shot is reviewed; nothing is redrawn.
        PoseMovedPerson::dispatch($keyframe, '', $box, redraw: false);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Fit the moved person's pose, gaze and gesture to their new spot: redrawn on a tight crop around them,
     * and only the person put back, with their feet where they were put.
     */
    public function fit(Request $request, Project $project, Shot $shot, Keyframe $keyframe)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);
        $this->ensureKeyframeIdle($shot, $keyframe, 'instruction');

        $instruction = trim((string) $request->validate(['instruction' => ['nullable', 'string', 'max:500']])['instruction']);
        $box = $keyframe->load('media')->render()?->getCustomProperty(Keyframe::MOVED_BOX);

        if (! is_array($box)) {
            throw ValidationException::withMessages(['instruction' => __('Move a person first: then their pose can be fitted to the new spot.')]);
        }

        $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();
        PoseMovedPerson::dispatch($keyframe, $instruction !== '' ? $instruction : "Give them the pose, gaze and gesture this describes, from where they stand now: {$keyframe->fullDescription()}", $box);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Finds people with the cut-out model's mask of this version: kept on it since it was composited or moved,
     * otherwise made once now. Without it, by what differs from the place.
     */
    private function cutoutFor(Media $render, LocalMediaFiles $files): PersonCutout
    {
        $stored = $render->getCustomProperty(Keyframe::PEOPLE_MASK);

        if (is_string($stored)) {
            return $this->cutout->withPeople(base64_decode($stored));
        }

        $found = $this->cutout->findPeople($files->path($render));

        if ($found !== null) {
            $render->setCustomProperty(Keyframe::PEOPLE_MASK, base64_encode($found))->save();
        }

        return $this->cutout->withPeople($found);
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

        if ($plate === null || ! $this->painter->isPlace($plate) || $render === null) {
            throw ValidationException::withMessages([$field => __('Only a keyframe drawn on a place can have a person moved.')]);
        }

        return [$plate, $render];
    }
}
