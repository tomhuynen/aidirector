<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\CutInBriefWriter;
use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\MovingThingsJudge;
use App\Ai\Agents\PlaceChangeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Jobs\CheckKeyframe;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\Cutout;
use App\Support\Images\Masks;
use App\Support\Images\OpenRouterImageClient;
use App\Support\Images\PlaceComposite;
use App\Support\Images\PlaceDepth;
use App\Support\Images\PlaceObjects;
use App\Support\Images\ReplicateClient;
use App\Support\Images\StartFrameStaging;
use App\Support\Images\TopLayerCutout;
use App\Support\Shots\SequencePlaces;
use finfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Generates one image for a keyframe, logs the generation with the prompt it
 * was sent, keeps the result as a new render and makes it the chosen one.
 */
class KeyframePainter
{
    /** On the storyline: the director asked for another camera than the master of the place, so the shot draws its own place. */
    public const OWN_CAMERA = 'own_camera';

    /** On a staged render of keyframe 1: where each cast member was staged, as boxes in shares of the image. */
    public const STAGING = 'staging';

    /** How many pictures of the cast a master of a sequence place gets: its people, what moves and the place. */
    private const int MASTER_REFERENCES = 5;

    /** How often a later state of the place is drawn before the last try is kept: the edit model sometimes removes or doubles a part that moves. */
    private const int PLACE_STATE_ATTEMPTS = 3;

    /** On the storyline of a shot that cuts in on a keyframe of another shot: what its own camera sees, with what it was written for. */
    public const CUT_IN_BRIEF = 'cut_in_brief';

    /** On the emptied place: by element id, the mask of each thing taken out of the place, small. */
    public const EMPTIED_MASKS = 'masks';

    /** Raised when what goes on top of the place is decided differently, so movable masks made before are made again. */
    private const int MOVABLE_RULE = 2;

    public function __construct(
        private readonly BackgroundDrift $drift,
        private readonly Cutout $cutout,
        private readonly PlaceComposite $composite,
        private readonly PlaceObjects $placeObjects,
    ) {}

    /**
     * With `$choose` off the render is only added as a version, for options the director picks from later.
     * The image model defaults to the keyframe model; tweaks pass the edit model.
     *
     * @param  array<int, StoredImage>  $attachments
     * @param  list<string>  $labels  what each attachment is, kept on the version with the prompt and the model
     *
     * @throws Throwable when the image model fails; the failure is logged on the keyframe first.
     */
    public function paint(Keyframe $keyframe, string $prompt, array $attachments = [], bool $choose = true, ?string $model = null, array $labels = []): Media
    {
        $shot = $keyframe->shot;
        $model ??= (string) Config::get('pipeline.models.keyframe');
        $started = hrtime(true);

        try {
            $result = OpenRouterImageClient::serves($model)
                ? $this->viaImagesEndpoint($model, $prompt, $attachments, $shot->aspectRatio()->value)
                : $this->viaChat($model, $prompt, $attachments, $shot->aspectRatio()->value);
        } catch (Throwable $exception) {
            $keyframe->generations()->create([
                'director_id' => $shot->project->director_id,
                'kind' => 'image',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $prompt,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $keyframe->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'image',
            'provider' => $result['provider'],
            'model' => $result['model'],
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $result['usage'],
        ]);

        $extension = match ($result['mime']) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        // Kept on the version, so the director can see exactly what the image model got.
        $render = $keyframe
            ->addMediaFromString($result['content'])
            ->usingFileName("keyframe-{$keyframe->position}.{$extension}")
            ->withCustomProperties([Keyframe::SENT => ['model' => $result['model'], 'prompt' => $prompt, 'images' => $labels]])
            ->toMediaCollection(Keyframe::RENDERS);

        if ($choose) {
            $keyframe->forceFill(['render_id' => $render->id, 'rendering' => false, 'render_error' => null])->save();
        }

        return $render;
    }

    /**
     * @param  array<int, StoredImage>  $attachments
     * @return array{content: string, mime: string, provider: string, model: string, usage: array<string, mixed>}
     */
    private function viaChat(string $model, string $prompt, array $attachments, string $aspectRatio): array
    {
        $response = Image::of($prompt)
            ->size($aspectRatio)
            ->quality(Config::get('pipeline.image_quality'))
            ->attachments($attachments)
            ->timeout(180)
            ->generate('openrouter', $model);

        $image = app(ImageReplies::class)->firstImage($response);

        return [
            'content' => $image->content(),
            'mime' => $image->mime(),
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'usage' => $response->usage->toArray(),
        ];
    }

    /**
     * @param  array<int, StoredImage>  $attachments
     * @return array{content: string, mime: string, provider: string, model: string, usage: array<string, mixed>}
     */
    private function viaImagesEndpoint(string $model, string $prompt, array $attachments, string $aspectRatio): array
    {
        $image = app(OpenRouterImageClient::class)->generate($model, $prompt, $attachments, $aspectRatio);

        return [
            'content' => $image['content'],
            'mime' => $image['mime'],
            'provider' => 'openrouter',
            'model' => $model,
            'usage' => array_filter(['cost' => $image['cost']], fn(mixed $value) => $value !== null),
        ];
    }

    /**
     * A stored render as a reference image for the model.
     */
    public function referenceFor(Media $render): StoredImage
    {
        return ImageFile::fromStorage($render->getPathRelativeToRoot(), $render->disk);
    }

    /**
     * The project's pinned style sheet as a reference image, when there is one.
     */
    public function styleReferenceFor(Project $project): ?StoredImage
    {
        $sheet = $project->styleReference();

        if ($sheet === null) {
            return null;
        }

        return $sheet->hasGeneratedConversion(Project::REFERENCE)
            ? ImageFile::fromStorage($sheet->getPathRelativeToRoot(Project::REFERENCE), $sheet->conversions_disk ?? $sheet->disk)
            : ImageFile::fromStorage($sheet->getPathRelativeToRoot(), $sheet->disk);
    }

    /**
     * Renders a keyframe from its planned brief with its references: builds
     * the prompt, stores it on the keyframe and paints. `$extra` is appended
     * to this render only, like the variation of an option for keyframe 1.
     *
     * @param  Collection<int, Keyframe>  $siblings  the shot's keyframes, with media and elements loaded
     */
    public function render(Keyframe $keyframe, Collection $siblings, string $extra = '', bool $choose = true): Media
    {
        $shot = $keyframe->shot;
        $plan = $shot->storylineKeyframes()[$keyframe->position - 1] ?? [
            'title' => $keyframe->title,
            'description' => $keyframe->description,
            'spatial' => $keyframe->spatial,
        ];

        $references = $this->referencesFor($keyframe, $siblings);
        $prompt = KeyframeImageBrief::for($shot, $plan, $references);

        $keyframe->forceFill(['prompt' => $prompt])->save();

        $base = $this->baseFor($keyframe, $siblings);
        // Keyframe 1 on the chosen place is staged first with grey placeholders, so what stands in front of what comes from the place; drawn the usual way when it cannot be staged.
        $render = $keyframe->position === 1 && $extra === '' ? $this->stagedFirst($keyframe, $plan, $references, $base, $choose) : null;
        $render ??= $this->paintOn($base, $keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose, labels: $references->labels());
        $keyframe->load('media');

        // Options for keyframe 1 are judged by the director; every keyframe that is used straight away is checked once, after it is shown.
        // A copy the director has not described yet repeats another keyframe's plan, so there is nothing of its own to check it against.
        if ($choose && ! $shot->plannedKeyframeIsCopy($keyframe->position)) {
            CheckKeyframe::start($keyframe, $render);
        }

        return $render;
    }

    /**
     * Keyframe 1 on the chosen place, staged: grey placeholders put into the place where a vision model put each cast
     * member, checked against the depth of the place, then made the cast by the edit model. It is composited onto the
     * place like any keyframe, so the place stays exact and only the people and things come from the drawing. Null
     * when it cannot be staged, such as without a chosen place.
     *
     * @param  array<string, mixed>  $plan
     */
    private function stagedFirst(Keyframe $keyframe, array $plan, KeyframeReferences $references, ?Media $base, bool $choose): ?Media
    {
        $shot = $keyframe->shot;
        $chosen = $this->chosenPlate($shot);

        if (! Config::get('pipeline.keyframes.staging.enabled') || ! $references->firstIsPlate || $chosen === null || $base === null) {
            return null;
        }

        $cast = array_values(array_filter($references->elementImages, fn(array $entry) => $entry['element']->type !== ElementType::PLACE));
        $description = trim((string) ($plan['description'] ?? $keyframe->description));
        $staged = app(StartFrameStaging::class)->stage(
            $this->bytes($base),
            $description,
            trim((string) ($plan['spatial'] ?? '')),
            array_map(fn(array $entry) => ['name' => $entry['element']->name, 'type' => $entry['element']->type->value], $cast),
            app(PlaceDepth::class)->stored($chosen),
        );

        if ($staged === null) {
            return null;
        }

        $stored = 'tmp/staging/' . Str::random(16) . '.png';
        Storage::disk('local')->put($stored, $staged['image']);
        $names = array_map(fn(array $entry) => $entry['element']->name, $cast);
        $castTypes = array_map(fn(array $entry) => ['name' => $entry['element']->name, 'type' => $entry['element']->type->value], $cast);
        $draw = fn(string $correction) => $this->paintOn(
            $base,
            $keyframe,
            KeyframeImageBrief::fromStaging($description, $names) . $correction,
            [ImageFile::fromStorage($stored, 'local'), ...array_column($cast, 'image')],
            $choose,
            (string) Config::get('pipeline.models.keyframe_edit'),
            ['The place with grey placeholders, the image that is edited', ...array_map(fn(string $name) => "Picture of {$name}", $names)],
        );

        try {
            $render = $draw('');
            // Checked against the staging: who is drawn elsewhere or far larger than their placeholder is drawn once more.
            $misplaced = app(StartFrameStaging::class)->misplaced($this->bytes($render), $staged['boxes'], $castTypes);

            if ($misplaced !== []) {
                $first = $render;
                $render = $draw("\nThe first attempt moved " . implode(' and ', $misplaced) . ' away from their placeholder or made them much larger: draw each exactly where their grey placeholder stands and at its size.');
                $misplaced = app(StartFrameStaging::class)->misplaced($this->bytes($render), $staged['boxes'], $castTypes);
                $first->delete();
            }
        } catch (Throwable $exception) {
            report($exception);
            Storage::disk('local')->delete($stored);

            return null;
        }

        // The staging and its boxes are kept with the version, so it can be seen later what the edit model got.
        $render->setCustomProperty(self::STAGING, $staged['boxes'])->save();
        $keyframe->addMediaFromDisk($stored, 'local')
            ->usingFileName("staging-{$keyframe->position}.png")
            ->withCustomProperties(['render' => $render->id])
            ->toMediaCollection(Keyframe::STAGINGS);
        Storage::disk('local')->delete($stored);

        if ($misplaced !== [] && $choose) {
            $shot->say(__('Keyframe 1 does not show :who where the staging put them. Tell me how to fix it, or draw it again.', ['who' => implode(' and ', $misplaced)]), [__('Draw it again')]);
        }

        return $render;
    }

    /**
     * Paints an edit of `$base` and makes sure the background stayed put:
     * measured in code, a render that zoomed or panned is drawn again, up
     * to the configured number of attempts. The stillest one is kept; when
     * even that one moved, it is marked so the director sees it.
     *
     * @param  list<StoredImage>  $attachments
     * @param  list<string>  $labels
     */
    public function paintOn(?Media $base, Keyframe $keyframe, string $prompt, array $attachments = [], bool $choose = true, ?string $model = null, array $labels = []): Media
    {
        // On a place only the people and the named things are kept, so the background cannot move and is not measured.
        if ($base !== null && $this->isPlace($base) && $this->composites()) {
            $drawn = $this->paint($keyframe, $prompt, $attachments, false, $model, $labels);

            if ($choose) {
                $keyframe->forceFill(['render_stage' => Keyframe::STAGE_COMPOSITING])->save();
            }

            try {
                $render = $this->compositeOn($base, $keyframe, $drawn);
            } finally {
                // A failure leaves no stage behind for the next drawing.
                if ($choose) {
                    $keyframe->forceFill(['render_stage' => null])->save();
                }
            }

            if ($choose) {
                $keyframe->forceFill(['render_id' => $render->id, 'rendering' => false, 'render_error' => null])->save();
            }

            return $render;
        }

        if ($base === null || ! Config::get('pipeline.keyframes.drift.enabled')) {
            return $this->paint($keyframe, $prompt, $attachments, $choose, $model, $labels);
        }

        $still = (float) Config::get('pipeline.keyframes.drift.min_stillness');
        $attempts = max(1, (int) Config::get('pipeline.keyframes.drift.attempts'));
        $best = null;
        $bestStillness = -1.0;
        $moved = true;

        for ($attempt = 1; $attempt <= $attempts && $moved; $attempt++) {
            $render = $this->paint($keyframe, $prompt, $attachments, false, $model, $labels);
            $measured = $this->drift->measure($base, $render, $still);
            $render->setCustomProperty(Keyframe::STILLNESS, round($measured['stillness'], 2))->save();

            // A render that did not move is kept; of renders that all moved, the stillest.
            if (! $measured['moved'] || $measured['stillness'] > $bestStillness) {
                $best?->delete();
                [$best, $bestStillness, $moved] = [$render, $measured['stillness'], $measured['moved']];
            } else {
                $render->delete();
            }
        }

        if ($moved) {
            $best->setCustomProperty(Keyframe::BACKGROUND_MOVED, true)->save();
        }

        if ($choose) {
            $keyframe->forceFill(['render_id' => $best->id, 'rendering' => false, 'render_error' => null])->save();
        }

        return $best;
    }

    /**
     * The image a keyframe is drawn on and must keep the background of: the
     * chosen place, the place made from keyframe 1, or keyframe 1 itself.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function baseFor(Keyframe $keyframe, Collection $siblings): ?Media
    {
        // Every still of a montage is drawn on its own.
        if ($keyframe->shot->drawsStandalone()) {
            return null;
        }

        $firstKeyframe = KeyframeImageBrief::usesFirstKeyframe($keyframe->position) ? $siblings->firstWhere('position', 1) : null;
        $plate = $this->placeAt($keyframe->shot, $siblings, $keyframe->position);

        return $plate ?? $firstKeyframe?->render();
    }

    /**
     * Looks at all keyframes of the shot together against its takeaway. Null
     * when the review itself fails; a review never holds up the shot.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{clear: bool, notes: list<array{text: string, keyframes: list<int>}>}|null
     */
    public function review(Shot $shot, Collection $keyframes): ?array
    {
        $rendered = $keyframes->filter(fn(Keyframe $keyframe) => $keyframe->render() !== null)->sortBy('position')->values();

        if ($rendered->count() < 2) {
            return null;
        }

        $reviewer = new ShotReviewer($shot, $rendered);
        $model = (string) Config::get('pipeline.models.text');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $reviewer->prompt($reviewer->promptFor(), attachments: $rendered->map(fn(Keyframe $keyframe) => $this->referenceFor($keyframe->render()))->all(), provider: 'openrouter', model: $model, timeout: 180);
        } catch (Throwable $exception) {
            $shot->generations()->create([
                'director_id' => $shot->project->director_id,
                'kind' => 'text',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $reviewer->promptFor(),
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return null;
        }

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $reviewer->promptFor(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $result = $response->toArray();

        $positions = $rendered->pluck('position')->all();
        $notes = collect((array) ($result['notes'] ?? []))
            ->map(fn(mixed $note) => [
                'text' => trim((string) (is_array($note) ? ($note['note'] ?? '') : $note)),
                'keyframes' => is_array($note)
                    ? collect((array) ($note['keyframes'] ?? []))->map(fn(mixed $position) => (int) $position)->intersect($positions)->unique()->sort()->values()->all()
                    : [],
            ])
            ->filter(fn(array $note) => $note['text'] !== '')
            ->values()
            ->all();

        return [
            'clear' => (bool) ($result['clear'] ?? true),
            'notes' => $notes,
        ];
    }

    /**
     * Has the checker compare a render with its references, each for its own
     * part: the place or keyframe 1, the pictures of the people for who they
     * are, and the keyframe before for the state of objects. Returns the
     * problems a viewer would see, empty when there are none, or null when
     * the check itself fails, so a broken check never holds up the shot.
     *
     * @param  Collection<int, Keyframe>  $siblings
     * @return list<string>|null
     */
    public function check(Keyframe $keyframe, Media $render, Collection $siblings): ?array
    {
        $references = $this->referencesFor($keyframe, $siblings);

        if ($references->first === null && ! $keyframe->shot->drawsStandalone()) {
            return null;
        }

        [$images, $roles] = $references->first === null ? $this->stillCheckImages($references) : $this->checkImages($references->first, $references);
        $images[] = $this->referenceFor($render);
        $roles[] = 'the keyframe to check';

        // On a composite the place is the place itself, so only the people, objects and the point of the keyframe are checked.
        $checker = new KeyframeChecker($keyframe, $roles, placeIsFixed: $render->getCustomProperty(Keyframe::COMPOSITED) === true);
        $result = $this->runChecker($keyframe, $checker, $images, (string) Config::get('pipeline.models.keyframe_check'));

        if ($result === null) {
            return null;
        }

        return array_values(array_map(
            fn(array $issue) => $issue['change'],
            array_filter($result['issues'], fn(array $issue) => $issue['severity'] === 'high'),
        ));
    }

    /**
     * The references a render is checked against, each with what it is the
     * reference for: keyframe 1 for the place and the camera, the pictures of
     * the people for who they are, and the keyframe before for the state of
     * objects and how the people look in this shot. The same images it was drawn from.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}
     */
    private function checkImages(StoredImage $first, KeyframeReferences $references): array
    {
        $images = [$first];
        $roles = [match (true) {
            $references->firstIsPlate => 'the place of this shot without people: the reference for the place and the camera',
            $references->firstShowsCast => 'keyframe 1 of the shot: the reference for the place and the camera, and for how the people look',
            default => 'keyframe 1 of the shot: the reference for the place and the camera; ' . implode(' and ', $references->castNames) . (count($references->castNames) > 1 ? ' are' : ' is') . ' not in it yet',
        }];

        foreach ($references->elementImages as $entry) {
            if ($entry['element']->type !== ElementType::PLACE) {
                $images[] = $entry['image'];
                $roles[] = "the picture of {$entry['element']->name}: the reference for how " . ($entry['element']->type === ElementType::PERSON ? 'this person' : 'this object') . ' looks';
            }
        }

        if ($references->previous !== null) {
            $images[] = $references->previous;
            $roles[] = 'the keyframe directly before: the reference for the state of objects, such as what is held or what is in a box, and for how the people look in this shot';
        }

        return [$images, $roles];
    }

    /**
     * The pictures a still of a montage is checked against: the people and
     * objects in it, each for how it looks. It has no place to keep.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}
     */
    private function stillCheckImages(KeyframeReferences $references): array
    {
        $images = [];
        $roles = [];

        foreach ($references->elementImages as $entry) {
            if ($entry['element']->type !== ElementType::PLACE) {
                $images[] = $entry['image'];
                $roles[] = "the picture of {$entry['element']->name}: the reference for how " . ($entry['element']->type === ElementType::PERSON ? 'this person' : 'this object') . ' looks';
            }
        }

        return [$images, $roles];
    }

    /**
     * @param  list<StoredImage>  $images
     * @return array{issues: list<array{category: string, change: string, severity: string}>}|null
     */
    private function runChecker(Keyframe $keyframe, KeyframeChecker $checker, array $images, string $model): ?array
    {
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $checker->prompt($checker->promptFor(), attachments: $images, provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            $keyframe->generations()->create([
                'director_id' => $keyframe->shot->project->director_id,
                'kind' => 'text',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $checker->promptFor(),
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return null;
        }

        $keyframe->generations()->create([
            'director_id' => $keyframe->shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $checker->promptFor(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $result = $response->toArray();
        $issues = array_values(array_filter(array_map(fn(mixed $issue) => is_array($issue) ? [
            'category' => (string) ($issue['category'] ?? ''),
            'change' => trim((string) ($issue['change'] ?? '')),
            'severity' => ($issue['severity'] ?? 'low') === 'high' ? 'high' : 'low',
        ] : null, (array) ($result['issues'] ?? [])), fn(?array $issue) => $issue !== null && $issue['change'] !== ''));

        return ['issues' => $issues];
    }

    /**
     * The style sheet, up to the configured number of the keyframe's cast and
     * sets with a reference image, keyframe 1 from the second keyframe on and
     * the keyframe directly before from the third on.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function referencesFor(Keyframe $keyframe, Collection $siblings): KeyframeReferences
    {
        $elements = $keyframe->elements->values();
        // A still of a montage has its own place: it is drawn like keyframe 1, from the style sheet and the pictures.
        $standalone = $keyframe->shot->drawsStandalone();
        $firstKeyframe = ! $standalone && KeyframeImageBrief::usesFirstKeyframe($keyframe->position) ? $siblings->firstWhere('position', 1) : null;
        // A chosen place is the base of every keyframe, keyframe 1 included; otherwise the place made from keyframe 1, if any.
        $plate = $standalone ? null : $this->placeAt($keyframe->shot, $siblings, $keyframe->position);
        $first = $plate ?? $firstKeyframe?->render();

        // On the plate keyframe 1 is not the base, so from keyframe 2 on the keyframe before carries how the people look.
        $previous = ! $standalone && (($plate !== null && $keyframe->position > 1) || KeyframeImageBrief::usesPreviousKeyframe($keyframe->position))
            ? $siblings->firstWhere('position', $keyframe->position - 1)?->render()
            : null;

        // Who the base image does not show yet: they are added when the keyframe is drawn on top of it.
        $people = $elements->filter(fn(Element $element) => $element->type === ElementType::PERSON);
        $missing = match (true) {
            $first === null => collect(),
            $plate !== null => $people->values(),
            default => $people->reject(fn(Element $person) => $firstKeyframe?->elements->contains($person))->values(),
        };

        // Drawn on top of keyframe 1, the place and the style come from it; only who and what is pictured stays.
        $elementImages = $elements
            ->filter(fn(Element $element) => $element->reference() !== null && ($first === null || $element->type !== ElementType::PLACE))
            // A presenter is one person, in front of their place.
            ->when($keyframe->shot->isPresenter(), fn(Collection $pictured) => $pictured->filter(fn(Element $element) => $element->type === ElementType::PLACE || $element->is($pictured->first(fn(Element $person) => $person->type === ElementType::PERSON))))
            ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();

        // Drawn from scratch, keyframe 1 can play in the setting of another shot, such as a close-up on the moment the shot before ends with.
        $setting = $first === null && ! $standalone ? $this->settingFor($keyframe->shot) : null;

        return new KeyframeReferences(
            style: $first === null ? $this->styleReferenceFor($keyframe->shot->project) : null,
            elements: $elements->all(),
            elementImages: $elementImages,
            first: $first ? $this->referenceFor($first) : null,
            previous: $previous ? $this->referenceFor($previous) : null,
            firstShowsCast: $missing->isEmpty(),
            castNames: $missing->pluck('name')->all(),
            thingNames: $plate !== null ? $this->listedNotIn($plate, $keyframe)->pluck('name')->all() : [],
            firstIsPlate: $plate !== null,
            setting: $setting['image'] ?? null,
            settingLabel: $setting['label'] ?? '',
            leftBehind: $setting['left']['image'] ?? null,
            leftBehindLabel: $setting['left']['label'] ?? '',
            // Keyframe 1 on a place cut from the master of its sequence: where the cast stands there.
            blocking: $plate !== null && $keyframe->position === 1 && ($blocking = $keyframe->shot->getFirstMedia(Shot::BLOCKING)) !== null ? $this->referenceFor($blocking) : null,
        );
    }

    /**
     * The image of the setting the shot takes from another shot, with what it
     * is; for a place of its sequence also the last keyframe of the shot before
     * there, when that one left something behind, such as a landed helicopter.
     *
     * @return array{image: StoredImage, label: string, left?: array{image: StoredImage, label: string}|null}|null
     */
    public function settingFor(Shot $shot): ?array
    {
        $from = $shot->settingFrom();

        if ($from === null) {
            $place = app(SequencePlaces::class)->of($shot);

            return $place === null || $place['image'] === null ? null : ['image' => $this->referenceFor($place['image']), 'label' => "{$place['name']}, the place chosen for this sequence of shots"
                . ($place['map'] !== null ? ". Where things stand in it: {$place['map']}" : '')
                . match (true) {
                    $place['brief'] !== null => " This shot's camera stands elsewhere: {$place['view']}. What this camera sees: {$place['brief']} Draw what this camera sees, with every fixed thing where the brief puts it in the frame; never copy the viewpoint of the attached picture, use it only for how the place and its things look",
                    $place['view'] !== null => " This shot shows: {$place['view']}; keep every fixed thing where the place has it, also what is out of this view",
                    default => '',
                }
                . ($place['left'] !== null ? " Since {$place['left']['shot']}, these stand in the place too and are drawn where they stand, as part of it: {$place['left']['text']}" : '')
                . (filled($blocking = $place['image']->getCustomProperty(SequencePlaces::BLOCKING)) ? " Where the cast stands at the start, in the picture the director chose; keep these sides: {$blocking}" : ''),
                'left' => ($place['left']['image'] ?? null) !== null ? ['image' => $this->referenceFor($place['left']['image']), 'label' => "the last keyframe of {$place['left']['shot']}, the same place from another camera at the end of that shot"] : null];
        }

        $source = $from['shot'];
        $image = $this->settingImage($shot);

        if ($image === null) {
            return null;
        }

        $label = match (true) {
            ! $from['chosen'] => "the last keyframe of {$source->code()}, the shot before",
            $from['keyframe'] === Shot::LAST_KEYFRAME => "the last keyframe of {$source->code()}",
            $from['keyframe'] === 0 => "the place of {$source->code()}",
            default => "keyframe {$from['keyframe']} of {$source->code()}",
        };
        $cutIn = $shot->storyline[self::CUT_IN_BRIEF] ?? null;

        // Cutting in on that moment from its own camera: what that camera sees is spelled out, so the picture's viewpoint is not copied.
        if (is_array($cutIn) && ($cutIn['for'] ?? null) === $this->cutInKey($shot, $image)) {
            $label .= ". This shot cuts in on that moment from its own camera: {$cutIn['text']} Draw what this camera sees, with what lies behind the subject from here; never copy the viewpoint of the attached picture, use it only for how the people, things and place look";
        }

        return ['image' => $this->referenceFor($image), 'label' => $label];
    }

    /**
     * The picture a shot takes its setting from: a keyframe of another shot, or that shot's place.
     */
    private function settingImage(Shot $shot): ?Media
    {
        $from = $shot->settingFrom();

        if ($from === null) {
            return null;
        }

        $source = $from['shot'];
        $position = $from['keyframe'] === Shot::LAST_KEYFRAME ? (int) $source->keyframes()->max('position') : $from['keyframe'];

        return $position > 0
            ? $source->keyframes()->with('media')->where('position', $position)->first()?->render()
            : ($this->chosenPlate($source) ?? $source->keyframes()->with('media')->where('position', 1)->first()?->render());
    }

    /**
     * Writes out what the camera of a shot that cuts in on a keyframe of another shot sees, such as a close-up at
     * a truck window after the wide shot of the gate: where it stands in that scene and what lies behind its
     * subject from there. Kept until the picture or the plan changes; without it the picture's viewpoint is copied.
     */
    public function writeCutInBrief(Shot $shot): void
    {
        $from = $shot->settingFrom();
        $image = $from !== null && $from['keyframe'] !== 0 ? $this->settingImage($shot) : null;
        $first = $shot->storylineKeyframes()[0] ?? null;

        if ($image === null || $first === null || (($shot->storyline[self::CUT_IN_BRIEF]['for'] ?? null) === $this->cutInKey($shot, $image))) {
            return;
        }

        $writer = new CutInBriefWriter($from['shot']->code(), $shot->kindOrScene() === ShotKind::CLOSE_UP ? 'close-up' : "{$shot->shotSize()->value} shot", (string) ($shot->storylineFraming()['spot'] ?? ''), Keyframe::joined((string) $first['description'], $first['spatial'] ?? null));

        try {
            /** @var StructuredAgentResponse $response */
            $response = $writer->prompt($writer->promptFor(), attachments: [$this->referenceFor($image)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $text = trim((string) ($response->toArray()['brief'] ?? ''));
        $key = $this->cutInKey($shot, $image);

        if ($text !== '') {
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), self::CUT_IN_BRIEF => ['for' => $key, 'text' => $text]]);
        }
    }

    /**
     * What a cut-in brief was written for: the picture and this shot's first keyframe.
     */
    private function cutInKey(Shot $shot, Media $image): string
    {
        $first = $shot->storylineKeyframes()[0] ?? [];

        return $image->id . ':' . hash('xxh3', (string) ($shot->storylineFraming()['spot'] ?? '') . '|' . ($first['description'] ?? ''));
    }

    /**
     * The empty place the director chose to start the shot from, if any.
     */
    public function chosenPlate(Shot $shot): ?Media
    {
        $plate = $shot->media()->where('collection_name', Shot::PLATE)->latest('id')->first();

        return $plate !== null && $plate->getCustomProperty(Shot::PLATE_CHOSEN) === true ? $plate : null;
    }

    /**
     * Draw one empty place to start the shot from, written from the whole
     * plan, with the style sheet and the pictures of the places and objects
     * the plan uses. People are left out; they are added per keyframe.
     */
    public function paintPlateOption(Shot $shot, int $variation, string $extra = ''): Media
    {
        $names = collect($shot->storylineKeyframes())->flatMap(fn(array $keyframe) => (array) ($keyframe['elements'] ?? []))->unique()->all();
        // Handled in keyframe 1 are the things that move by themselves in this shot: left out of the place, so their pictures are not attached.
        $moving = array_map(fn(mixed $name) => mb_strtolower(trim((string) $name)), (array) ($shot->storylineKeyframes()[0]['handled'] ?? []));
        $elementImages = $shot->project->elements()->with('media')->get()
            ->filter(fn(Element $element) => in_array($element->name, $names, true) && $element->type !== ElementType::PERSON && $element->reference() !== null && ! in_array(mb_strtolower($element->name), $moving, true))
            ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();
        $setting = $this->settingFor($shot);
        $references = new KeyframeReferences(style: $this->styleReferenceFor($shot->project), elementImages: $elementImages, setting: $setting['image'] ?? null, settingLabel: $setting['label'] ?? '', leftBehind: $setting['left']['image'] ?? null, leftBehindLabel: $setting['left']['label'] ?? '');

        $prompt = KeyframeImageBrief::plate($shot, $references, $variation);

        return $this->drawPlateOption($shot, $extra === '' ? $prompt : "{$prompt}\n{$extra}", $references->images(), $references->labels(), (string) Config::get('pipeline.models.keyframe'));
    }

    /**
     * Draws one option of a place a sequence of shots plays in, wide and empty,
     * with what happens there in each shot; kept on the first shot of the sequence.
     *
     * @param  array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}  $place
     */
    public function paintSequencePlaceOption(Shot $owner, array $place, int $variation): Media
    {
        // The master: the cast is in it, people first, at where they start; the empty place is made from it once chosen.
        $elementImages = $owner->project->elements()->with('media')->get()
            ->filter(fn(Element $element) => in_array($element->name, $place['elements'], true) && $element->reference() !== null)
            ->sortBy(fn(Element $element) => match ($element->type) {
                ElementType::PERSON => 0,
                ElementType::OBJECT => 1,
                default => 2,
            })
            ->take(self::MASTER_REFERENCES)
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();
        $references = new KeyframeReferences(style: $this->styleReferenceFor($owner->project), elementImages: $elementImages);
        $prompt = KeyframeImageBrief::sequencePlace($owner, $place, $references, $variation);
        $result = $this->drawForShot($owner, $prompt, $references->images(), (string) Config::get('pipeline.models.keyframe'));

        return $owner->addMediaFromString($result['content'])
            ->usingFileName('sequence-place.' . ($result['mime'] === 'image/jpeg' ? 'jpg' : 'png'))
            ->withCustomProperties(['place' => $place['key'], Keyframe::SENT => ['model' => $result['model'], 'prompt' => $prompt, 'images' => $references->labels()]])
            ->toMediaCollection(Shot::SEQUENCE_PLACE_OPTIONS);
    }

    /**
     * The place of a scene in the place of its sequence, cut from the empty place made from the master the director
     * chose, around where the cast stands: the whole picture for a full shot, closer around them for a medium one.
     * With it, the same cut of the master, so keyframe 1 puts everyone where they stand there, on the same side.
     * Null when the place has no master, for a close-up, for a shot that continues another or that asked for its
     * own camera: those draw their own place.
     *
     * @return array{plate: string, blocking: string, depth: array<string, mixed>|null}|null the depth of the empty place when the cut is the whole of it
     */
    public function masterPlate(Shot $shot): ?array
    {
        $places = app(SequencePlaces::class);
        $empty = $places->chosenFor($shot);
        $master = $places->masterFor($shot);
        $box = $empty?->getCustomProperty(SequencePlaces::CAST_BOX);

        if ($empty === null || $master === null || ! is_array($box) || ! $shot->kindOrScene()->usesPlace() || $shot->settingFrom() !== null || ($shot->storyline[self::OWN_CAMERA] ?? false) === true) {
            return null;
        }

        $place = new Imagick();
        $place->readImageBlob($this->bytes($empty));
        $people = new Imagick();
        $people->readImageBlob($this->bytes($master));
        [$width, $height] = [$place->getImageWidth(), $place->getImageHeight()];

        if ($people->getImageWidth() !== $width || $people->getImageHeight() !== $height) {
            $people->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $whole = $shot->shotSize() !== ShotSize::MEDIUM;

        // A medium shot comes closer around the cast, keeping the shape of the frame; a full shot is the whole picture.
        if (! $whole) {
            $cropHeight = (int) round($height * min(1, max(0.45, (float) $box['height'] * 1.5)));
            $cropWidth = min($width, (int) round($cropHeight * $width / $height));
            $centreX = ((float) $box['x'] + (float) $box['width'] / 2) * $width;
            $centreY = ((float) $box['y'] + (float) $box['height'] / 2) * $height;
            $left = (int) round(min(max(0, $centreX - $cropWidth / 2), $width - $cropWidth));
            $top = (int) round(min(max(0, $centreY - $cropHeight / 2), $height - $cropHeight));

            foreach ([$place, $people] as $image) {
                $image->cropImage($cropWidth, $cropHeight, $left, $top);
                $image->setImagePage(0, 0, 0, 0);
                $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
            }
        }

        $place->setImageFormat('png');
        $people->setImageFormat('png');

        $depth = $empty->getCustomProperty(PlaceDepth::DEPTH);

        return ['plate' => $place->getImageBlob(), 'blocking' => $people->getImageBlob(), 'depth' => $whole && is_array($depth) ? $depth : null];
    }

    /**
     * A picture of a place without what is named, such as its people and a truck: the edit model takes them out,
     * and only where the mask has them, and a little around, its result is used.
     *
     * @throws Throwable when the image model fails
     */
    public function emptyOf(Shot $shot, Media $picture, string $what, string $mask): string
    {
        $prompt = KeyframeImageBrief::withoutThing($what);
        $emptied = $this->drawForShot($shot, $prompt, [$this->referenceFor($picture)], (string) Config::get('pipeline.models.keyframe_edit'));

        return $this->composite->withoutPeople($this->bytes($picture), $emptied['content'], $mask);
    }

    /**
     * The things of a place of a sequence that move in its shots, in lower case, judged once from what happens there.
     *
     * @param  array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}  $place
     * @return list<string>
     */
    public function movingAt(Shot $owner, array $place): array
    {
        $objects = $owner->project->elements()->where('type', ElementType::OBJECT)->whereIn('name', $place['elements'])->pluck('name')->unique()->values()->all();

        if ($objects === []) {
            return [];
        }

        return Cache::remember('moving-things:' . hash('xxh3', json_encode([$objects, $place['ideas']], JSON_THROW_ON_ERROR)), now()->addWeek(), function () use ($objects, $place): array {
            $judge = new MovingThingsJudge($objects, array_values(array_filter($place['ideas'])));

            try {
                /** @var StructuredAgentResponse $response */
                $response = $judge->prompt($judge->promptFor(), provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
            } catch (Throwable $exception) {
                report($exception);

                return [];
            }

            return array_values(array_map(fn(mixed $name) => mb_strtolower(trim((string) $name)), (array) ($response->toArray()['moving'] ?? [])));
        });
    }

    /**
     * Adjust one empty place with a change the director asks for; the result
     * is added as a new place, so the original stays available.
     */
    public function adjustPlateOption(Shot $shot, Media $option, string $instruction): Media
    {
        $plate = $this->drawPlateOption(
            $shot,
            KeyframeImageBrief::tweakPlate($instruction),
            [$this->referenceFor($option)],
            ['This place, the image that is edited'],
            (string) Config::get('pipeline.models.keyframe_edit'),
        );

        $plate->setCustomProperty(Keyframe::TWEAK_REQUEST, $instruction)->save();

        return $plate;
    }

    /**
     * Draw an empty place and add it to the places to choose from.
     *
     * @param  list<StoredImage>  $images
     * @param  list<string>  $labels
     */
    private function drawPlateOption(Shot $shot, string $prompt, array $images, array $labels, string $model): Media
    {
        $result = $this->drawForShot($shot, $prompt, $images, $model);

        return $shot->addMediaFromString($result['content'])
            ->usingFileName('place.' . ($result['mime'] === 'image/jpeg' ? 'jpg' : 'png'))
            ->withCustomProperties([Keyframe::SENT => ['model' => $result['model'], 'prompt' => $prompt, 'images' => $labels]])
            ->toMediaCollection(Shot::PLATE_OPTIONS);
    }

    /**
     * Draw an image for the shot itself, such as a place, logged on the shot.
     *
     * @param  list<StoredImage>  $images
     * @return array{content: string, mime: string, provider: string, model: string, usage: array<string, mixed>}
     */
    private function drawForShot(Shot $shot, string $prompt, array $images, string $model): array
    {
        $started = hrtime(true);

        try {
            $result = OpenRouterImageClient::serves($model)
                ? $this->viaImagesEndpoint($model, $prompt, $images, $shot->aspectRatio()->value)
                : $this->viaChat($model, $prompt, $images, $shot->aspectRatio()->value);
        } catch (Throwable $exception) {
            $shot->generations()->create(['director_id' => $shot->project->director_id, 'kind' => 'image', 'provider' => 'openrouter', 'model' => $model, 'prompt' => $prompt, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'error' => $exception->getMessage()]);

            throw $exception;
        }

        $shot->generations()->create(['director_id' => $shot->project->director_id, 'kind' => 'image', 'provider' => $result['provider'], 'model' => $result['model'], 'prompt' => $prompt, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'usage' => $result['usage']]);

        return $result;
    }

    /**
     * Use the chosen place as keyframe 1 as it is, for a first keyframe without people.
     */
    public function useChosenPlate(Keyframe $keyframe, Media $plate): Media
    {
        $render = $plate->copy($keyframe, Keyframe::RENDERS);
        $render->setCustomProperty(Keyframe::SENT, ['model' => '', 'prompt' => 'The chosen place, used as it is.', 'images' => ['The chosen place']])->save();
        $keyframe->forceFill(['render_id' => $render->id, 'rendering' => false, 'render_error' => null, 'render_stage' => null])->save();

        return $render;
    }

    /**
     * Whether a version is the chosen place used as it is, for a keyframe 1 without people.
     */
    public function isChosenPlateCopy(Media $render): bool
    {
        return ($render->getCustomProperty(Keyframe::SENT)['prompt'] ?? null) === 'The chosen place, used as it is.';
    }

    /**
     * The thing of the place at a point given as shares of the image, such as a
     * landed helicopter: one of the keyframe's objects that the place has, with
     * its mask in the place, from the emptied place when it is made. Null when
     * the point is on none of them.
     *
     * @return array{element: Element, mask: string}|null
     */
    public function placeObjectAt(Keyframe $keyframe, Media $place, float $x, float $y): ?array
    {
        $emptied = (array) ($this->emptiedPlate($keyframe->shot, $place)?->getCustomProperty(self::EMPTIED_MASKS) ?? []);

        foreach ($keyframe->elements->filter(fn(Element $element) => $element->type === ElementType::OBJECT) as $object) {
            $mask = isset($emptied[(string) $object->id]) ? base64_decode((string) $emptied[(string) $object->id]) : null;

            if ($mask === null && (! $this->placeObjects->isIn($place, $object) || ($mask = $this->placeObjects->maskIn($place, $object)) === null)) {
                continue;
            }

            $image = Masks::grey($mask);
            [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];
            $pixel = $image->getImagePixelColor((int) round($x * ($width - 1)), (int) round($y * ($height - 1)))->getColor();

            if ($pixel['r'] > 127) {
                return ['element' => $object, 'mask' => $mask];
            }
        }

        return null;
    }

    /**
     * Whether the chosen place is still being made ready for moving things by hand: its depth is being measured or
     * its named things are being taken out. Null when nothing is to be done, otherwise what is still missing.
     *
     * @return array{plate: Media, depth: bool, emptied: bool}|null
     */
    public function placeToPrepare(Shot $shot): ?array
    {
        $plate = $this->composites() && ! $shot->drawsStandalone() ? $this->chosenPlate($shot) : null;

        if ($plate === null) {
            return null;
        }

        $depth = ! is_array($plate->getCustomProperty(PlaceDepth::DEPTH)) && $plate->getCustomProperty(PlaceDepth::TRIED) !== true;
        $emptied = $plate->getCustomProperty(Shot::PLATE_EMPTIED_DONE) !== true;

        return $depth || $emptied ? ['plate' => $plate, 'depth' => $depth, 'emptied' => $emptied] : null;
    }

    public function preparingPlace(Shot $shot): bool
    {
        return $this->placeToPrepare($shot) !== null;
    }

    /**
     * The chosen place without the named things it has, made once for it, or null while it is not made.
     */
    public function emptiedPlate(Shot $shot, Media $plate): ?Media
    {
        $emptied = $shot->getFirstMedia(Shot::PLATE_EMPTIED);

        return $emptied !== null && (int) $emptied->getCustomProperty(Keyframe::FROM_RENDER) === $plate->id ? $emptied : null;
    }

    /**
     * Makes the chosen place without the things of the cast it has, such as a
     * landed helicopter and a bin, so each can be moved by hand at once later:
     * the edit model takes them all out in one go, and only where they stood,
     * and a little around, its result is used. Each keeps its mask, with the
     * edges of the cut-out model. Walls, railings and what is not in the cast
     * stay. Null when the place has none of them.
     *
     * @throws Throwable when the image model fails
     */
    public function emptyPlate(Shot $shot, Media $plate): ?Media
    {
        $names = collect($shot->storylineKeyframes())->flatMap(fn(array $keyframe) => (array) ($keyframe['elements'] ?? []))->map(fn(mixed $name) => mb_strtolower(trim((string) $name)))->unique();
        $objects = $shot->project->elements()->with('media')->where('type', ElementType::OBJECT)->get()->filter(fn(Element $element) => $names->contains(mb_strtolower($element->name)));
        $image = $this->bytes($plate);
        $masks = [];

        foreach ($objects as $object) {
            if (! $this->placeObjects->isIn($plate, $object) || ($mask = $this->placeObjects->maskIn($plate, $object)) === null) {
                continue;
            }

            try {
                $mask = $this->cutout->refined($image, $mask);
            } catch (Throwable $exception) {
                report($exception);
            }

            $masks[(string) $object->id] = ['name' => $object->name, 'mask' => $mask];
        }

        if ($masks === []) {
            return null;
        }

        $found = array_values($masks);
        $prompt = KeyframeImageBrief::withoutThing(implode(' and the ', array_column($found, 'name')));
        $emptied = $this->drawForShot($shot, $prompt, [$this->referenceFor($plate)], (string) Config::get('pipeline.models.keyframe_edit'));
        $union = TopLayerCutout::keptSmall($found[0]['mask'], array_column(array_slice($found, 1), 'mask'));

        return $shot->addMediaFromString($this->composite->withoutPeople($image, $emptied['content'], $union))
            ->usingFileName('place-emptied.png')
            ->withCustomProperties([
                Keyframe::FROM_RENDER => $plate->id,
                self::EMPTIED_MASKS => array_map(fn(array $thing) => base64_encode(TopLayerCutout::keptSmall($thing['mask'])), $masks),
                Keyframe::SENT => ['model' => $emptied['model'], 'prompt' => $prompt, 'images' => ['The chosen place']],
            ])
            ->toMediaCollection(Shot::PLATE_EMPTIED);
    }

    /**
     * Whether keyframe 1 shows people, so later keyframes need the place
     * without them. Keyframe 1 without people is that place already.
     */
    public function needsPlate(Keyframe $first): bool
    {
        return $first->elements->contains(fn(Element $element) => $element->type === ElementType::PERSON);
    }

    /**
     * The plate made from the current render of keyframe 1, if there is one.
     */
    public function plateFor(Shot $shot, Keyframe $first): ?Media
    {
        // Looked up fresh: the plate may have been made after the shot's media were loaded.
        $plate = $shot->media()->where('collection_name', Shot::PLATE)->latest('id')->first();
        $render = $first->render();

        return $plate !== null && $render !== null && (int) $plate->getCustomProperty(Shot::PLATE_FROM) === (int) $render->id ? $plate : null;
    }

    /**
     * Make sure later keyframes have a still place to be drawn on: when
     * keyframe 1 shows people, its picture without them, made once with
     * the keyframe edit model. A failure is logged and the keyframes are
     * drawn on keyframe 1 itself instead.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function ensurePlate(Shot $shot, Collection $siblings): ?Media
    {
        if (($chosen = $this->chosenPlate($shot)) !== null) {
            return $chosen;
        }

        $first = $siblings->firstWhere('position', 1);
        $render = $first?->render();

        if ($first === null || $render === null || ! $this->needsPlate($first)) {
            return null;
        }

        if (($plate = $this->plateFor($shot, $first)) !== null) {
            return $plate;
        }

        $model = (string) Config::get('pipeline.models.keyframe_edit');
        $prompt = implode("\n", [
            'Edit the attached image: remove every person from it.',
            'Keep everything else exactly as it is: the place, the camera, the framing, every object, sign and machine, the floor and every marking or painted line on it, the light and the style.',
            'Where a person stood, show the floor, the wall or whatever was behind them, continuing the lines and surfaces around it.',
            'Do not add anything and do not add text.',
        ]);
        $started = hrtime(true);

        try {
            $result = OpenRouterImageClient::serves($model)
                ? $this->viaImagesEndpoint($model, $prompt, [$this->referenceFor($render)], $shot->aspectRatio()->value)
                : $this->viaChat($model, $prompt, [$this->referenceFor($render)], $shot->aspectRatio()->value);
        } catch (Throwable $exception) {
            $shot->generations()->create([
                'director_id' => $shot->project->director_id,
                'kind' => 'image',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $prompt,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);
            report($exception);

            return null;
        }

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'image',
            'provider' => $result['provider'],
            'model' => $result['model'],
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $result['usage'],
        ]);

        return $shot->addMediaFromString($result['content'])
            ->usingFileName('plate.' . ($result['mime'] === 'image/jpeg' ? 'jpg' : 'png'))
            ->withCustomProperties([Shot::PLATE_FROM => $render->id, Keyframe::SENT => ['model' => $result['model'], 'prompt' => $prompt, 'images' => ['Keyframe 1']]])
            ->toMediaCollection(Shot::PLATE);
    }

    /**
     * Whether keyframes on a place keep only their people and named things:
     * switched on and with a Replicate token for the cut-outs.
     */
    public function composites(): bool
    {
        return (bool) Config::get('pipeline.keyframes.composite.enabled') && ReplicateClient::configured();
    }

    /**
     * Whether the image is an empty place: the shot's place or a later state of it.
     */
    public function isPlace(Media $image): bool
    {
        return in_array($image->collection_name, [Shot::PLATE, Shot::PLACE_STATES], true);
    }

    /**
     * Keeps of a render drawn on a place only the people, their shadows and
     * the objects they handle, and puts them on the place itself; objects
     * nobody handles stay exactly as in the place, also behind someone. The render is replaced by
     * the composite. It stays as it was drawn when the people cannot be cut
     * out, or when an object of the keyframe cannot be found in the place:
     * its state might have changed, and half a change is worse than a
     * background that moved a little.
     */
    public function compositeOn(Media $place, Keyframe $keyframe, Media $render): Media
    {
        $drawn = $this->bytes($render);

        try {
            ['people' => $people, 'areas' => $areas, 'movable' => $movable, 'missing' => $missing] = $this->topLayer($keyframe, $place, $drawn);
        } catch (Throwable $exception) {
            report($exception);

            return $render;
        }

        if ($missing !== null) {
            $render->setCustomProperty(Keyframe::NOT_COMPOSITED, __(':name could not be found in the place, so the keyframe is kept as it was drawn.', ['name' => $missing]))->save();

            return $render;
        }

        try {
            $composite = $this->composite->keyframe($this->bytes($place), $drawn, $people, $areas);
        } catch (Throwable $exception) {
            report($exception);

            return $render;
        }

        $kept = $keyframe
            ->addMediaFromString($composite)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([...$render->custom_properties, Keyframe::COMPOSITED => true, ...$this->movableProperties($keyframe, $people, $movable)])
            ->toMediaCollection(Keyframe::RENDERS);
        $render->delete();
        $keyframe->load('media');

        return $kept;
    }

    /**
     * What a keyframe drawn on a place keeps on top of it, as the plan says:
     * its people, found by name with the edges of the cut-out model, and the
     * things it handles. Of those, what can be moved by hand: the people and
     * the handled things that are not in the place. Throws when the people
     * cannot be found.
     *
     * @return array{people: string, areas: list<string>, movable: list<string>, missing: string|null}
     */
    public function topLayer(Keyframe $keyframe, Media $place, string $image): array
    {
        $people = $this->mayHavePeople($keyframe) ? $this->cutout->people($image) : Cutout::nobody($image);

        return ['people' => $people, ...$this->drawnAreas($place, $keyframe, $people, $image)];
    }

    /**
     * What can be moved by hand in an image of a keyframe, kept small enough to store on a version:
     * all of it, and of that the things, such as a helicopter flying in, which move without a shadow.
     *
     * @return array{all: string, things: string|null}
     */
    public function movableMasks(Keyframe $keyframe, Media $place, string $image): array
    {
        ['people' => $people, 'movable' => $movable] = $this->topLayer($keyframe, $place, $image);

        return ['all' => TopLayerCutout::keptSmall($people, $movable), 'things' => $movable !== [] ? TopLayerCutout::keptSmall($movable[0], array_slice($movable, 1)) : null];
    }

    /**
     * The movable masks as stored on a version, with what they were made from.
     *
     * @param  list<string>  $movable
     * @return array<string, string|null>
     */
    public function movableProperties(Keyframe $keyframe, string $people, array $movable): array
    {
        return [
            Keyframe::TOP_LAYER_MASK => base64_encode(TopLayerCutout::keptSmall($people, $movable)),
            Keyframe::THINGS_MASK => $movable !== [] ? base64_encode(TopLayerCutout::keptSmall($movable[0], array_slice($movable, 1))) : null,
            Keyframe::TOP_LAYER_KEY => $this->movableKey($keyframe),
        ];
    }

    /**
     * What a stored movable mask was made from: the cast and what is handled. A mask
     * made from another, or before this was kept, is made again.
     */
    public function movableKey(Keyframe $keyframe): string
    {
        $plan = $keyframe->shot->storylineKeyframes()[$keyframe->position - 1] ?? [];
        $cast = $keyframe->elements->pluck('id')->sort()->values()->all();

        return hash('xxh3', json_encode([self::MOVABLE_RULE, $cast, $plan['handled'] ?? null], JSON_THROW_ON_ERROR));
    }

    /**
     * The things a keyframe lists that the place does not have, such as a
     * helicopter that flies in: drawn on the place, they are added like the
     * people. None for a plan from before it said what is handled.
     *
     * @return Collection<int, Element>
     */
    private function listedNotIn(Media $place, Keyframe $keyframe): Collection
    {
        if ($this->handledIn($keyframe) === null) {
            return collect();
        }

        return $keyframe->elements
            ->filter(fn(Element $element) => $element->type === ElementType::OBJECT)
            ->reject(fn(Element $object) => $this->placeObjects->isIn($place, $object))
            ->values();
    }

    /**
     * Whether a keyframe may have people in it: when its cast has a person, and
     * when it has no cast at all, as with a plan from before casts were kept.
     * Otherwise the name finder often takes part of what stands in front, such
     * as a parked helicopter, for a person.
     */
    private function mayHavePeople(Keyframe $keyframe): bool
    {
        return $keyframe->elements->isEmpty() || $keyframe->elements->contains(fn(Element $element) => $element->type === ElementType::PERSON);
    }

    /**
     * Where the drawing is used for an object instead of the place itself.
     * What the keyframe lists that the place does not have, such as a
     * helicopter that flies in, a badge carried in or a helicopter landed in
     * a shot whose sequence sees it take off later, can only come from the
     * drawing: it is found there, and can be moved by hand. Of what the place
     * has, only what someone changes in this keyframe, such as a handset off
     * its cradle, as the plan says, both at its spot in the place and where
     * the drawing has it now, such as a truck that drives off; standing in front of something is not
     * changing it, and a thing the place has would show twice if it were
     * moved. Plans from before they said what is handled fall back on what
     * the people touch, and leave what the place lacks to the person holding
     * it. Names the object when it cannot be found in the place.
     *
     * @return array{areas: list<string>, movable: list<string>, missing: string|null}
     */
    private function drawnAreas(Media $place, Keyframe $keyframe, string $people, string $drawn): array
    {
        $handled = $this->handledIn($keyframe);
        $areas = [];
        $movable = [];

        foreach ($keyframe->elements->filter(fn(Element $element) => $element->type === ElementType::OBJECT) as $object) {
            if (! $this->placeObjects->isIn($place, $object)) {
                $inDrawing = $handled !== null ? $this->placeObjects->maskInDrawing($drawn, $object) : null;

                if ($inDrawing !== null) {
                    // Found by name, its edge from the cut-out model, the same as a person.
                    try {
                        $inDrawing = $this->cutout->refined($drawn, $inDrawing);
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    $areas[] = $inDrawing;
                    $movable[] = $inDrawing;
                }

                continue;
            }

            if ($handled !== null && ! in_array(mb_strtolower($object->name), $handled, true)) {
                continue;
            }

            $area = $this->placeObjects->maskIn($place, $object);

            if ($area === null) {
                return ['areas' => [], 'movable' => [], 'missing' => $object->name];
            }

            if ($handled !== null || $this->composite->touches($area, $people)) {
                $areas[] = $area;
            }

            // Handled, it may have moved away from its spot in the place, such as a truck that drives off: where it is now comes from the drawing too.
            if ($handled !== null && ($moved = $this->placeObjects->maskInDrawing($drawn, $object)) !== null) {
                try {
                    $moved = $this->cutout->refined($drawn, $moved);
                } catch (Throwable $exception) {
                    report($exception);
                }

                $areas[] = $moved;
            }
        }

        // Parts of the place that move in this keyframe, such as a barrier arm going up: where they were and where they are now come from the drawing.
        foreach ($this->movingPartsIn($keyframe) as $part) {
            foreach ([$this->placeObjects->partIn($place, $part), $this->placeObjects->partInDrawing($drawn, $part)] as $area) {
                if ($area !== null) {
                    $areas[] = $area;
                }
            }
        }

        return ['areas' => $areas, 'movable' => $movable, 'missing' => null];
    }

    /**
     * The parts of the place the plan has move in a keyframe, in plain words, such as "barrier arm".
     *
     * @return list<string>
     */
    private function movingPartsIn(Keyframe $keyframe): array
    {
        $plan = $keyframe->shot->storylineKeyframes()[$keyframe->position - 1] ?? [];

        return array_values(array_filter(array_map(fn(mixed $part) => trim((string) $part), (array) ($plan['moving_parts'] ?? []))));
    }

    /**
     * The names of what the plan handles in a keyframe, in lower case; null for a plan from before it said.
     *
     * @return list<string>|null
     */
    private function handledIn(Keyframe $keyframe): ?array
    {
        $plan = $keyframe->shot->storylineKeyframes()[$keyframe->position - 1] ?? [];

        return array_key_exists('handled', $plan) ? array_map(fn(mixed $name) => mb_strtolower(trim((string) $name)), (array) $plan['handled']) : null;
    }

    /**
     * The empty place a keyframe is drawn on: the shot's place, in the latest
     * state the plan reached by this keyframe, such as with the door closed
     * from keyframe 2 on. States not made yet are skipped.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function placeAt(Shot $shot, Collection $siblings, int $position): ?Media
    {
        $first = $siblings->firstWhere('position', 1);
        $place = $this->chosenPlate($shot) ?? ($first !== null && $position > 1 ? $this->plateFor($shot, $first) : null);

        if ($place === null) {
            return null;
        }

        $states = $shot->media()->where('collection_name', Shot::PLACE_STATES)->get()->keyBy(fn(Media $state) => (string) $state->getCustomProperty(Shot::PLACE_STATE_KEY));

        foreach ($this->placeChanges($shot, $position) as $change) {
            $place = $states->get($this->stateKey($place, $change)) ?? $place;
        }

        return $place;
    }

    /**
     * Makes the states of the place the plan needs, each from the one before:
     * the image model draws the change on the empty place, and only the
     * changed thing is kept, so the rest of the place stays as it was. A
     * failure is logged; the keyframes are then drawn on the state before.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function ensurePlaceStates(Shot $shot, Collection $siblings): void
    {
        $first = $siblings->firstWhere('position', 1);
        $place = $this->chosenPlate($shot) ?? ($first !== null ? $this->plateFor($shot, $first) : null);

        if ($place === null || $shot->drawsStandalone()) {
            return;
        }

        $states = $shot->media()->where('collection_name', Shot::PLACE_STATES)->get()->keyBy(fn(Media $state) => (string) $state->getCustomProperty(Shot::PLACE_STATE_KEY));

        foreach ($this->placeChanges($shot, count($shot->storylineKeyframes())) as $change) {
            $key = $this->stateKey($place, $change);
            $place = $states->get($key) ?? $this->drawPlaceState($shot, $place, $change, $key) ?? $place;
        }
    }

    /**
     * The changes to the place the plan makes up to a keyframe, in order.
     *
     * @return list<array{position: int, change: string, part: string}>
     */
    private function placeChanges(Shot $shot, int $position): array
    {
        // What the plan changes in the place, from keyframe 2 on; then what the director changed in it, from any keyframe on.
        // A shot cut from the master of its sequence may start with a changed place, as an earlier shot left it, such as the barrier up.
        $fromMaster = $shot->media()->where('collection_name', Shot::BLOCKING)->exists();
        $planned = collect($shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ['position' => $index + 1, 'change' => trim((string) ($keyframe['place_change'] ?? '')), 'part' => trim((string) ($keyframe['place_part'] ?? ''))])
            ->filter(fn(array $change) => $change['position'] > 1 || $fromMaster);
        $edits = collect($shot->placeEdits())->map(fn(array $edit) => ['position' => $edit['from'], 'change' => $edit['change'], 'part' => $edit['part']]);

        return $planned->concat($edits)
            ->filter(fn(array $change) => $change['position'] <= $position && $change['change'] !== '')
            ->sortBy('position')
            ->values()
            ->all();
    }

    /**
     * Puts the people of a keyframe drawn on the place onto a newer version
     * of that place, such as one without a door handle, without drawing them
     * again: they, their shadows and the objects they touch are cut out of
     * the current version. Returns the new version, or null when the cut-out
     * fails or the keyframe is not a composite, so it has to be drawn again.
     */
    public function recomposite(Keyframe $keyframe, Media $place): ?Media
    {
        $render = $keyframe->render();

        if ($render === null || $render->getCustomProperty(Keyframe::COMPOSITED) !== true) {
            return null;
        }

        $current = $this->bytes($render);

        try {
            ['people' => $people, 'areas' => $areas, 'movable' => $movable, 'missing' => $missing] = $this->topLayer($keyframe, $place, $current);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($missing !== null) {
            return null;
        }

        try {
            $composite = $this->composite->keyframe($this->bytes($place), $current, $people, $areas);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $kept = $keyframe
            ->addMediaFromString($composite)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([...$render->custom_properties, ...$this->movableProperties($keyframe, $people, $movable)])
            ->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $kept->id])->save();
        $render->delete();
        $keyframe->load('media');

        return $kept;
    }

    /**
     * @param  array{position: int, change: string, part: string}  $change
     */
    private function stateKey(Media $place, array $change): string
    {
        return hash('sha256', "{$place->id}|{$change['position']}|{$change['change']}|{$change['part']}");
    }

    /**
     * @param  array{position: int, change: string, part: string}  $change
     */
    private function drawPlaceState(Shot $shot, Media $place, array $change, string $key): ?Media
    {
        $before = $this->bytes($place);

        // Drawn again when the change does not show, such as a barrier arm still across the road, or a second arm.
        for ($attempt = 1; $attempt <= self::PLACE_STATE_ATTEMPTS; $attempt++) {
            try {
                $result = $this->drawForShot($shot, KeyframeImageBrief::placeState($change['change'], $change['part']), [$this->referenceFor($place)], (string) Config::get('pipeline.models.keyframe_edit'));
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            $image = $result['content'];
            $composited = false;

            // Only the changed thing comes from the edit, found in the place before and after, so both its old and new shape are covered.
            if ($this->composites() && $change['part'] !== '') {
                try {
                    $image = $this->composite->state($before, $image, [$this->cutout->thing($before, $change['part']), $this->cutout->thing($image, $change['part'])]);
                    $composited = true;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            if ($this->changeShows($before, $image, $change['change'], $change['part'])) {
                break;
            }
        }

        return $shot->addMediaFromString($image)
            ->usingFileName("place-{$change['position']}.png")
            ->withCustomProperties([
                Shot::PLACE_STATE_KEY => $key,
                'position' => $change['position'],
                'change' => $change['change'],
                Keyframe::COMPOSITED => $composited,
                Keyframe::SENT => ['model' => $result['model'], 'prompt' => KeyframeImageBrief::placeState($change['change'], $change['part']), 'images' => ['The place before this change']],
            ])
            ->toMediaCollection(Shot::PLACE_STATES);
    }

    /**
     * Whether a new version of the place shows the change asked for, judged by a vision model; without an answer it counts as shown.
     */
    private function changeShows(string $before, string $after, string $change, string $part = ''): bool
    {
        $checker = new PlaceChangeChecker($change, $part);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $checker->prompt($checker->promptFor(), attachments: array_map(fn(string $image) => ImageFile::fromBase64(base64_encode($image), (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($image)), [$before, $after]), provider: 'openrouter', model: (string) Config::get('pipeline.models.keyframe_check'));
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }

        return (bool) ($response->toArray()['shown'] ?? true);
    }

    /**
     * The presenter's keyframe without the presenter, so their video can be
     * put on a background that never moves: the edit model takes them out,
     * and only around them its result is used. Made once per render.
     *
     * @throws Throwable when the image model or the cut-out fails
     */
    public function presenterBackground(Keyframe $keyframe, Media $render): Media
    {
        $made = $keyframe->getFirstMedia(Keyframe::PRESENTER_BACKGROUND);

        if ($made !== null && (int) $made->getCustomProperty(Keyframe::FROM_RENDER) === $render->id) {
            return $made;
        }

        $still = $this->bytes($render);
        $prompt = KeyframeImageBrief::withoutPeople();
        $emptied = $this->drawForShot($keyframe->shot, $prompt, [$this->referenceFor($render)], (string) Config::get('pipeline.models.keyframe_edit'));
        $background = $this->composite->withoutPeople($still, $emptied['content'], $this->cutout->people($still));

        return $keyframe
            ->addMediaFromString($background)
            ->usingFileName("presenter-background-{$keyframe->position}.png")
            ->withCustomProperties([Keyframe::FROM_RENDER => $render->id, Keyframe::SENT => ['model' => $emptied['model'], 'prompt' => $prompt, 'images' => ['The keyframe, the image that is edited']]])
            ->toMediaCollection(Keyframe::PRESENTER_BACKGROUND);
    }

    private function bytes(Media $media): string
    {
        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    }
}
