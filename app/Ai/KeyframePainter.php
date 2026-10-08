<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\ElementType;
use App\Jobs\CheckKeyframePlace;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\Cutout;
use App\Support\Images\OpenRouterImageClient;
use App\Support\Images\PlaceComposite;
use App\Support\Images\ReplicateClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
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
    public function __construct(
        private readonly BackgroundDrift $drift,
        private readonly Cutout $cutout,
        private readonly PlaceComposite $composite,
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

        $render = $this->paintOn($this->baseFor($keyframe, $siblings), $keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose, labels: $references->labels());
        $keyframe->load('media');

        // Options for keyframe 1 are judged by the director; every keyframe that is used straight away is checked once.
        // A copy the director has not described yet repeats another keyframe's plan, so there is nothing of its own to check it against.
        if (! $choose || ! Config::get('pipeline.keyframe_check') || $shot->plannedKeyframeIsCopy($keyframe->position)) {
            return $render;
        }

        // The keyframe stays busy while it is checked, so the director sees why it takes longer.
        $keyframe->forceFill(['rendering' => true, 'render_stage' => Keyframe::STAGE_CHECKING])->save();
        $check = $this->check($keyframe, $render, $references);

        $keyframe->forceFill(['rendering' => false, 'render_stage' => null])->save();

        // The check advises and never redraws by itself: what it found becomes notes the director can have fixed or dismiss.
        if ($check !== null && ! $check['passes']) {
            $render->setCustomProperty(Keyframe::CHECK_ISSUES, $check['problems'])->save();
        }

        $keyframe->load('media');

        // A composite has the place itself as its background; there is no shift to look for.
        if ($render->getCustomProperty(Keyframe::COMPOSITED) !== true) {
            CheckKeyframePlace::start($keyframe, $render);
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
            $render = $this->compositeOn($base, $keyframe, $this->paint($keyframe, $prompt, $attachments, false, $model, $labels));

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
            $response = $reviewer->prompt($reviewer->promptFor(), attachments: $rendered->map(fn(Keyframe $keyframe) => $this->referenceFor($keyframe->render()))->all(), provider: 'openrouter', model: $model);
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
     * part: keyframe 1 for the place, the keyframe the people first appear in
     * (or their picture) for how they look, and the keyframe before for the
     * state of objects. High issues count against the render. Returns null
     * when the check itself fails, so a broken check never holds up the shot.
     *
     * @return array{passes: bool, problems: list<string>, fix: string}|null
     */
    private function check(Keyframe $keyframe, Media $render, KeyframeReferences $references): ?array
    {
        if ($references->first === null && ! $keyframe->shot->drawsStandalone()) {
            return null;
        }

        [$images, $roles] = $references->first === null ? $this->stillCheckImages($references) : $this->checkImages($references->first, $references);
        $images[] = $this->referenceFor($render);
        $roles[] = 'the keyframe to check';

        $checker = new KeyframeChecker($keyframe, $roles);
        $result = $this->runChecker($keyframe, $checker, $images, (string) Config::get('pipeline.models.keyframe_check'));

        if ($result === null) {
            return null;
        }

        $high = array_values(array_filter($result['issues'], fn(array $issue) => $issue['severity'] === 'high'));
        $problems = array_map(fn(array $issue) => $issue['change'], $high);

        return [
            'passes' => $problems === [],
            'problems' => $problems,
            'fix' => $problems === [] ? '' : 'Keep everything else as it is and correct this: ' . implode(' ', $problems),
        ];
    }

    /**
     * Has the place checker compare a render with keyframe 1, for shifts in
     * the place that the full check tends to miss. Returns the changes a
     * viewer would see, or null when the check fails.
     *
     * @return list<string>|null
     */
    public function checkPlace(Keyframe $keyframe, Media $render, KeyframeReferences $references): ?array
    {
        if ($references->first === null) {
            return null;
        }

        // The keyframe before shows whether a change jumps between the two, or was already there.
        $before = $keyframe->position > ($references->firstIsPlate ? 1 : 2) ? $references->previous : null;
        $checker = new KeyframeChecker($keyframe, array_values(array_filter([
            'keyframe 1 of the shot, the reference for the place',
            $before !== null ? 'the keyframe directly before the one to check' : null,
            'the keyframe to check',
        ])), scope: KeyframeChecker::PLACE);
        $result = $this->runChecker($keyframe, $checker, array_values(array_filter([$references->first, $before, $this->referenceFor($render)])), (string) Config::get('pipeline.models.place_check'));

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
            firstIsPlate: $plate !== null,
            setting: $setting['image'] ?? null,
            settingLabel: $setting['label'] ?? '',
        );
    }

    /**
     * The image of the setting the shot takes from another shot, with what it is.
     *
     * @return array{image: StoredImage, label: string}|null
     */
    public function settingFor(Shot $shot): ?array
    {
        $from = $shot->settingFrom();

        if ($from === null) {
            return null;
        }

        $source = $from['shot'];
        $position = $from['keyframe'] === Shot::LAST_KEYFRAME ? (int) $source->keyframes()->max('position') : $from['keyframe'];
        $keyframe = $position > 0 ? $source->keyframes()->with('media')->where('position', $position)->first() : null;
        $image = $position > 0
            ? $keyframe?->render()
            : ($this->chosenPlate($source) ?? $source->keyframes()->with('media')->where('position', 1)->first()?->render());

        if ($image === null) {
            return null;
        }

        $label = match (true) {
            ! $from['chosen'] => "the last keyframe of {$source->code()}, the shot before",
            $from['keyframe'] === Shot::LAST_KEYFRAME => "the last keyframe of {$source->code()}",
            $from['keyframe'] === 0 => "the place of {$source->code()}",
            default => "keyframe {$from['keyframe']} of {$source->code()}",
        };

        return ['image' => $this->referenceFor($image), 'label' => $label];
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
    public function paintPlateOption(Shot $shot, int $variation): Media
    {
        $names = collect($shot->storylineKeyframes())->flatMap(fn(array $keyframe) => (array) ($keyframe['elements'] ?? []))->unique()->all();
        $elementImages = $shot->project->elements()->with('media')->get()
            ->filter(fn(Element $element) => in_array($element->name, $names, true) && $element->type !== ElementType::PERSON && $element->reference() !== null)
            ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();
        $setting = $this->settingFor($shot);
        $references = new KeyframeReferences(style: $this->styleReferenceFor($shot->project), elementImages: $elementImages, setting: $setting['image'] ?? null, settingLabel: $setting['label'] ?? '');

        return $this->drawPlateOption($shot, KeyframeImageBrief::plate($shot, $references, $variation), $references->images(), $references->labels(), (string) Config::get('pipeline.models.keyframe'));
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
     * the things named in the keyframe, and puts them on the place itself.
     * The render is replaced by the composite. When the cut-out fails, the
     * render stays as it was drawn.
     */
    public function compositeOn(Media $place, Keyframe $keyframe, Media $render): Media
    {
        $drawn = $this->bytes($render);
        // The objects take their state from the keyframe, such as a handset lifted off its cradle; places and people are covered otherwise.
        $things = $keyframe->elements->filter(fn(Element $element) => $element->type === ElementType::OBJECT)->pluck('name')->values()->all();

        try {
            $composite = $this->composite->keyframe(
                $this->bytes($place),
                $drawn,
                $this->cutout->people($drawn),
                array_map(fn(string $name) => $this->cutout->thing($drawn, $name), $things),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $render;
        }

        $kept = $keyframe
            ->addMediaFromString($composite)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([...$render->custom_properties, Keyframe::COMPOSITED => true])
            ->toMediaCollection(Keyframe::RENDERS);
        $render->delete();
        $keyframe->load('media');

        return $kept;
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
        return collect($shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ['position' => $index + 1, 'change' => trim((string) ($keyframe['place_change'] ?? '')), 'part' => trim((string) ($keyframe['place_part'] ?? ''))])
            ->filter(fn(array $change) => $change['position'] > 1 && $change['position'] <= $position && $change['change'] !== '')
            ->values()
            ->all();
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
        try {
            $result = $this->drawForShot($shot, KeyframeImageBrief::placeState($change['change']), [$this->referenceFor($place)], (string) Config::get('pipeline.models.keyframe_edit'));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $before = $this->bytes($place);
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

        return $shot->addMediaFromString($image)
            ->usingFileName("place-{$change['position']}.png")
            ->withCustomProperties([
                Shot::PLACE_STATE_KEY => $key,
                'position' => $change['position'],
                'change' => $change['change'],
                Keyframe::COMPOSITED => $composited,
                Keyframe::SENT => ['model' => $result['model'], 'prompt' => KeyframeImageBrief::placeState($change['change']), 'images' => ['The place before this change']],
            ])
            ->toMediaCollection(Shot::PLACE_STATES);
    }

    private function bytes(Media $media): string
    {
        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    }
}
