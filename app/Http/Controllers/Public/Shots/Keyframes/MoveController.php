<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Ai\KeyframePainter;
use App\Http\Controllers\Public\Shots\Concerns\GuardsBusyShots;
use App\Http\Requests\Public\KeyframeMoveRequest;
use App\Jobs\EmptyPlace;
use App\Jobs\PoseMovedPerson;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\DepthGrid;
use App\Support\Images\FluxFill;
use App\Support\Images\PlaceDepth;
use App\Support\Images\ReplicateClient;
use App\Support\Images\TopLayerCutout;
use App\Support\Media\LocalMediaFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Moving a person or thing in a keyframe by hand: the director clicks it in
 * the image and drags it to a new spot, where it is put down at once, sized
 * by the depth of the place and hidden behind what stands in front of it.
 * Fitting a person's pose, gaze and gesture to the new spot is a separate
 * step the director asks for. Only keyframes drawn on a place, which is what
 * tells what was added apart from the background.
 */
class MoveController
{
    use GuardsBusyShots;

    public function __construct(
        private readonly KeyframePainter $painter,
        private readonly TopLayerCutout $cutout,
        private readonly PlaceDepth $depth,
        private readonly FluxFill $fill,
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
        $cutout = $this->cutoutFor($keyframe, $plate, $render, $files, 'x');
        $shape = $cutout->select($files->path($plate), $files->path($render), (float) $point['x'], (float) $point['y']);
        $placeObject = null;

        // Nothing on top there: maybe a thing of the place, such as a landed helicopter, moved in this keyframe only.
        if ($shape === null && $render->getCustomProperty(Keyframe::ON_EMPTIED_PLACE) !== true && ($found = $this->placeObjectAt($keyframe, $plate, (float) $point['x'], (float) $point['y'])) !== null) {
            $cutout = $this->cutout->withTopLayer($found['mask'])->withThings($found['mask']);
            $shape = $cutout->select($files->path($plate), $files->path($render), (float) $point['x'], (float) $point['y']);
            $placeObject = $found['element']->name;

            // Normally made when the place was chosen; for a place chosen before that, now.
            if ($this->painter->emptiedPlate($shot, $plate) === null) {
                EmptyPlace::dispatch($shot, $plate);
            }
        }

        if ($shape === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person or thing you want to move.')]);
        }

        return response()->json([
            'box' => $shape['box'],
            ...$cutout->previews($files->path($plate), $files->path($render), $shape),
            /** @var array{width: int, height: int, values: array<int, float>}|null */
            'depth' => $this->depthOf($shot, $plate)?->forEditor(),
            /** @var string|null A thing of the place that was clicked, such as a landed helicopter: it moves in this keyframe only. */
            'placeObject' => $placeObject,
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
        $cutout = $this->cutoutFor($keyframe, $plate, $render, $files, 'x');
        $box = $cutout->move(
            $files->path($plate),
            $files->path($render),
            (float) $request->validated('x'),
            (float) $request->validated('y'),
            (float) $request->validated('dx'),
            (float) $request->validated('dy'),
            (float) $request->validated('scale'),
            $output,
            $this->depthOf($shot, $plate),
        );

        if ($box === null) {
            return $this->movePlaceObject($request, $project, $shot, $keyframe, $plate, $render, $files);
        }

        $this->blendVacated($cutout, $output);

        $moved = $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([
                Keyframe::SENT => ['model' => '', 'prompt' => 'Moved by hand.', 'images' => ['The place without people', 'The version before']],
                Keyframe::MOVED_BOX => $box,
                ...($cutout->movedThing() ? [Keyframe::MOVED_THING => true] : []),
                // Still a composite: a change of the place later puts what is on top onto it without drawing it again.
                ...array_filter([Keyframe::COMPOSITED => $render->getCustomProperty(Keyframe::COMPOSITED)]),
                ...(($moved = $cutout->movedTopLayer()) !== null ? [Keyframe::TOP_LAYER_MASK => base64_encode($moved), Keyframe::TOP_LAYER_KEY => $this->painter->movableKey($keyframe)] : []),
                ...(($movedThings = $cutout->movedThings()) !== null ? [Keyframe::THINGS_MASK => base64_encode($movedThings)] : []),
            ])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $moved->id, 'render_error' => null])->save();
        $this->keepLayers($keyframe, $render, $moved, $cutout);

        $this->afterMove($request, $keyframe, $box, $cutout->movedThing());

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * After a move the description and the plan follow the image, then the shot is reviewed. Asked to redraw, what
     * was moved is drawn again at its new spot first, so it sits there with its own shadow, light and, for a person,
     * pose; otherwise nothing is redrawn.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     */
    private function afterMove(KeyframeMoveRequest $request, Keyframe $keyframe, array $box, bool $thing): void
    {
        if (! $request->boolean('redraw')) {
            PoseMovedPerson::dispatch($keyframe, '', $box, redraw: false);

            return;
        }

        $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();
        PoseMovedPerson::dispatch($keyframe, $thing
            ? 'Redraw what was moved by hand where it stands now, so it sits naturally in the place: where it meets the ground, its shadow and its light for this spot. Keep how it looks, its size and which way it faces.'
            : "Redraw the person moved by hand where they stand now, so they sit naturally in the place: their shadow and light for this spot, and the pose, gaze and gesture this describes from there: {$keyframe->fullDescription()}", $box);
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
        $render = $keyframe->load('media')->render();
        $box = $render?->getCustomProperty(Keyframe::MOVED_BOX);

        if (! is_array($box) || $render->getCustomProperty(Keyframe::MOVED_THING) === true) {
            throw ValidationException::withMessages(['instruction' => __('Move a person first: then their pose can be fitted to the new spot.')]);
        }

        $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();
        PoseMovedPerson::dispatch($keyframe, $instruction !== '' ? $instruction : "Give them the pose, gaze and gesture this describes, from where they stand now: {$keyframe->fullDescription()}", $box);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * Moves a thing of the place at the point, such as a landed helicopter, in this keyframe only: where it stood,
     * the place without its named things shows, made when the place was chosen. From then on the keyframe lies on
     * that emptied place, with all its named things on top, so each of them can be moved, and it is no composite
     * of the place any more.
     */
    private function movePlaceObject(KeyframeMoveRequest $request, Project $project, Shot $shot, Keyframe $keyframe, Media $plate, Media $render, LocalMediaFiles $files)
    {
        [$x, $y] = [(float) $request->validated('x'), (float) $request->validated('y')];
        $found = $render->getCustomProperty(Keyframe::ON_EMPTIED_PLACE) === true ? null : $this->placeObjectAt($keyframe, $plate, $x, $y);

        if ($found === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person or thing you want to move.')]);
        }

        $emptied = $this->painter->emptiedPlate($shot, $plate);

        if ($emptied === null) {
            EmptyPlace::dispatch($shot, $plate);

            throw ValidationException::withMessages(['x' => __('The place is still being made ready for moving its things. Try again in a moment.')]);
        }

        $output = storage_path('app/tmp/moved-' . Str::random(12) . '.png');
        $cutout = $this->cutout->withTopLayer($found['mask'])->withThings($found['mask']);
        $box = $cutout->move($files->path($emptied), $files->path($render), $x, $y, (float) $request->validated('dx'), (float) $request->validated('dy'), (float) $request->validated('scale'), $output, $this->depthOf($shot, $plate));

        if ($box === null) {
            throw ValidationException::withMessages(['x' => __('Nothing was added to the place there. Click the person or thing you want to move.')]);
        }

        $this->blendVacated($cutout, $output);

        // The other named things stay where they are, now on top of the emptied place.
        $others = array_values(array_map(fn(mixed $mask) => base64_decode((string) $mask), array_diff_key((array) $emptied->getCustomProperty(KeyframePainter::EMPTIED_MASKS, []), [(string) $found['element']->id => true])));
        $stored = fn(string $key) => is_string($render->getCustomProperty($key)) ? [base64_decode($render->getCustomProperty($key))] : [];
        $top = [(string) $cutout->movedTopLayer(), ...$stored(Keyframe::TOP_LAYER_MASK), ...$others];
        $things = [(string) $cutout->movedThings(), ...$stored(Keyframe::THINGS_MASK), ...$others];

        $moved = $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([
                Keyframe::SENT => ['model' => '', 'prompt' => "Moved by hand: the {$found['element']->name}, in this keyframe only.", 'images' => ['The place without its named things', 'The version before']],
                Keyframe::MOVED_BOX => $box,
                Keyframe::MOVED_THING => true,
                Keyframe::ON_EMPTIED_PLACE => true,
                Keyframe::TOP_LAYER_MASK => base64_encode(TopLayerCutout::keptSmall($top[0], array_slice($top, 1))),
                Keyframe::THINGS_MASK => base64_encode(TopLayerCutout::keptSmall($things[0], array_slice($things, 1))),
                Keyframe::TOP_LAYER_KEY => $this->painter->movableKey($keyframe),
            ])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $moved->id, 'render_error' => null])->save();
        $this->keepLayers($keyframe, $render, $moved, $cutout);

        $this->afterMove($request, $keyframe, $box, true);

        return redirect()->route('public.shots.view', [$project, $shot]);
    }

    /**
     * The depth of the place as measured when it was chosen, never measured now: a click must not wait for it.
     * Its later states and the place without its named things have the camera of the chosen place.
     */
    /**
     * Where the moved one stood shows the place again, drawn apart from this keyframe, so its ground can be another
     * shade and the old shadow can stay. FLUX Fill redraws just that area, fitted to what is around it; the moved
     * one and the rest stay as they are. When the fill fails, the move stays as it is.
     */
    private function blendVacated(TopLayerCutout $cutout, string $output): void
    {
        $vacated = $cutout->vacated();

        if ($vacated === null || ! Config::get('pipeline.keyframes.move.blend')) {
            return;
        }

        try {
            file_put_contents($output, $this->fill->fill((string) file_get_contents($output), $vacated, 'The ground and the place exactly as around this area, continuing its surface, markings, edges and light seamlessly. Empty ground.'));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function depthOf(Shot $shot, Media $place): ?DepthGrid
    {
        return $this->depth->stored($this->painter->chosenPlate($shot) ?? $place);
    }

    /**
     * A thing of the place at the point; only on a shot that composites, which keeps where its things are.
     *
     * @return array{element: \App\Models\Element, mask: string}|null
     */
    private function placeObjectAt(Keyframe $keyframe, Media $plate, float $x, float $y): ?array
    {
        if (! $this->painter->composites()) {
            return null;
        }

        try {
            return $this->painter->placeObjectAt($keyframe, $plate, $x, $y);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Finds what can be moved with the mask of this version: kept on it since it was composited or moved,
     * made again when the cast or what is handled changed since, otherwise made once now, the same way the
     * composite makes it. Without Replicate, by what differs from the place.
     */
    private function cutoutFor(Keyframe $keyframe, Media $plate, Media $render, LocalMediaFiles $files, string $field): TopLayerCutout
    {
        $stored = $render->getCustomProperty(Keyframe::TOP_LAYER_MASK);
        $key = $this->painter->movableKey($keyframe);

        // Without Replicate an older mask is still better than what differs from the place.
        if (is_string($stored) && ($render->getCustomProperty(Keyframe::TOP_LAYER_KEY) === $key || ! ReplicateClient::configured())) {
            $things = $render->getCustomProperty(Keyframe::THINGS_MASK);

            return $this->cutout->withTopLayer(base64_decode($stored))->withThings(is_string($things) ? base64_decode($things) : null)->withLayers($this->layersOf($keyframe, $render));
        }

        if (! ReplicateClient::configured()) {
            return $this->cutout->withTopLayer(null)->withLayers($this->layersOf($keyframe, $render));
        }

        try {
            ['all' => $found, 'things' => $things] = $this->painter->movableMasks($keyframe, $plate, (string) file_get_contents($files->path($render)));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __('What can be moved could not be found just now. Try again in a moment.')]);
        }

        $render->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode($found))
            ->setCustomProperty(Keyframe::THINGS_MASK, $things !== null ? base64_encode($things) : null)
            ->setCustomProperty(Keyframe::TOP_LAYER_KEY, $key)
            ->save();

        return $this->cutout->withTopLayer($found)->withThings($things)->withLayers($this->layersOf($keyframe, $render));
    }

    /**
     * The whole cut-outs of what was moved by hand on this version, also the parts outside the frame.
     *
     * @return list<array{image: string, box: array{x: float, y: float, width: float, height: float}, thing: bool}>
     */
    private function layersOf(Keyframe $keyframe, Media $render): array
    {
        return $keyframe->getMedia(Keyframe::LAYERS)
            ->filter(fn(Media $layer) => (int) $layer->getCustomProperty('render') === $render->id)
            ->map(fn(Media $layer) => [
                'image' => (string) Storage::disk($layer->disk)->get($layer->getPathRelativeToRoot()),
                'box' => array_map('floatval', (array) $layer->getCustomProperty('box')),
                'thing' => $layer->getCustomProperty('thing') === true,
            ])
            ->values()
            ->all();
    }

    /**
     * Keeps the whole cut-outs on the moved version: the one just moved, where it lies now, and the others as they were.
     */
    private function keepLayers(Keyframe $keyframe, Media $before, Media $moved, TopLayerCutout $cutout): void
    {
        $kept = $keyframe->getMedia(Keyframe::LAYERS)->filter(fn(Media $layer) => (int) $layer->getCustomProperty('render') === $before->id)->values();

        foreach ($kept as $index => $layer) {
            if ($index !== $cutout->usedLayer()) {
                $layer->copy($keyframe, Keyframe::LAYERS)->setCustomProperty('render', $moved->id)->save();
            }
        }

        if (($layer = $cutout->movedLayer()) !== null) {
            $keyframe->addMediaFromString($layer['image'])
                ->usingFileName("layer-{$keyframe->position}.png")
                ->withCustomProperties(['render' => $moved->id, 'box' => $layer['box'], 'thing' => $layer['thing']])
                ->toMediaCollection(Keyframe::LAYERS);
        }
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
            throw ValidationException::withMessages([$field => __('Only a keyframe drawn on a place can have a person or thing moved.')]);
        }

        // A keyframe whose thing of the place was moved lies on the place without its named things.
        $emptied = $render->getCustomProperty(Keyframe::ON_EMPTIED_PLACE) === true ? $this->painter->emptiedPlate($shot, $plate) : null;

        return [$emptied ?? $plate, $render];
    }
}
