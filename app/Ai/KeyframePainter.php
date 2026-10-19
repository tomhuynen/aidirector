<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\OpenRouterImageClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
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
        private readonly LayoutPainter $layouts,
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
            $result = $this->draw($model, $prompt, $attachments, $shot->aspectRatio()->value);
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
            $keyframe->forceFill(['render_id' => $render->id, 'render_error' => null])->save();
        }

        return $render;
    }

    /**
     * One image from the model, through the endpoint that serves it; nothing is stored.
     *
     * @param  array<int, StoredImage>  $attachments
     * @return array{content: string, mime: string, provider: string, model: string, usage: array<string, mixed>}
     */
    public function draw(string $model, string $prompt, array $attachments, string $aspectRatio): array
    {
        return OpenRouterImageClient::serves($model)
            ? $this->viaImagesEndpoint($model, $prompt, $attachments, $aspectRatio)
            : $this->viaChat($model, $prompt, $attachments, $aspectRatio);
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
     * to this render only, like the variation of an option for the start frame.
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

        $base = $this->baseFor($keyframe, $siblings);

        // The start frame of a shot that returns to an earlier camera is drawn on that camera's last frame; another
        // start frame of a scene or close-up is drawn whole and read; the end frame whole by the keyframe model
        // from it, or on it by the layout model when set; a still from its brief.
        if ($keyframe->position === 1 && ($view = $this->viewFor($keyframe)) !== null) {
            $render = $this->paintReturn($keyframe, $view, $prompt, $references, $choose, $extra);
        } elseif ($keyframe->position === 1 && $this->layouts->reads($keyframe)) {
            $render = $this->paintFirst($keyframe, $plan, $choose, $extra);
        } elseif (Config::get('pipeline.keyframes.layout.draws_keyframes') && $this->layouts->draws($keyframe, $base)) {
            $render = $this->paintLayout($keyframe, $base, $siblings, $choose, $extra);
        } else {
            $keyframe->forceFill(['prompt' => $prompt])->save();
            $render = $this->paintOn($base, $keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose, labels: $references->labels());

            if ($choose && $base !== null && $this->layouts->reads($keyframe)) {
                $this->readLayout($keyframe, $render);
            }
        }

        $keyframe->load('media');

        // The start frame of a shot that continues another remembers the end frame it was drawn from.
        if ($choose && $keyframe->position === 1 && ($source = $shot->settingRender()) !== null) {
            $keyframe->forceFill(['source_render_id' => $source->id])->save();
        }

        // Options for the start frame are judged by the director; every keyframe that is used straight away is checked once.
        // A copy the director has not described yet repeats another keyframe's plan, so there is nothing of its own to check it against.
        if (! $choose || ! Config::get('pipeline.keyframe_check') || $shot->plannedKeyframeIsCopy($keyframe->position)) {
            return $render;
        }

        // The keyframe shows it is being checked, so the director sees why it takes longer.
        $keyframe->forceFill(['status' => KeyframeStatus::CHECKING])->save();
        $check = $this->check($keyframe, $render, $references);

        // The check advises and never redraws by itself: what it found becomes notes the director can have fixed or dismiss.
        if ($check !== null && ! $check['passes']) {
            $render->setCustomProperty(Keyframe::CHECK_ISSUES, [...(array) $render->getCustomProperty(Keyframe::CHECK_ISSUES, []), ...$check['problems']])->save();
        }

        $keyframe->load('media');

        return $render;
    }

    /**
     * Draws a keyframe on the image before it with the {@see LayoutPainter},
     * logs the generation, keeps the result as a new render and the layout
     * on the keyframe, for the keyframe after it.
     *
     * @param  Collection<int, Keyframe>  $siblings
     *
     * @throws Throwable when the layout model fails; the failure is logged on the keyframe first.
     */
    private function paintLayout(Keyframe $keyframe, Media $base, Collection $siblings, bool $choose, string $extra): Media
    {
        // The depth of the place is measured on the start frame, where the camera of the shot was set.
        $result = $this->logged($keyframe, fn() => $this->layouts->draw($keyframe, $base, $siblings, $siblings->firstWhere('position', 1)?->render(), $extra));
        $render = $this->keep($keyframe, $result, $choose);
        $render->setCustomProperty(Keyframe::LAYOUT, $result['layout']->toArray())->save();
        // An option that is not chosen keeps its layout to itself, so the next keyframe starts from the chosen one.
        $keyframe->forceFill(['prompt' => $result['prompt'], ...($choose ? ['layout' => $result['layout']->toArray()] : [])])->save();

        return $render;
    }

    /**
     * Moves one of the cast in a keyframe to the box the director dragged
     * them to, as a new render that is chosen, and keeps the new layout.
     *
     * @param  Collection<int, Keyframe>  $siblings
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     */
    public function move(Keyframe $keyframe, Collection $siblings, string $name, array $box): Media
    {
        $render = $keyframe->render() ?? throw new RuntimeException('There is no image to move anything in.');
        $result = $this->logged($keyframe, fn() => $this->layouts->move($keyframe, $render, $siblings->firstWhere('position', 1)?->render(), $name, $box));
        $moved = $this->keep($keyframe, $result, true);
        $keyframe->forceFill(['layout' => $result['layout']->toArray()])->save();

        return $moved;
    }

    /**
     * Changes one of the cast in a keyframe where they stand, as the director
     * asked in the chat, keeping everything else of the image.
     */
    public function change(Keyframe $keyframe, string $name, string $change): Media
    {
        $render = $keyframe->render() ?? throw new RuntimeException('There is no image to change anything in.');

        $changed = $this->keep($keyframe, $this->logged($keyframe, fn() => $this->layouts->change($keyframe, $render, [$name => $change])), true);
        $changed->setCustomProperty(Keyframe::TWEAK_REQUEST, $change)->save();

        return $changed;
    }

    /**
     * Fixes what a check found in a keyframe, changing only where each
     * problem is and keeping everything else of the image. A changed camera
     * or framing cannot be fixed in a part of the image: it stays a note, to
     * draw the keyframe again for.
     *
     * @param  list<string>  $problems
     */
    public function repair(Keyframe $keyframe, array $problems): Media
    {
        $render = $keyframe->render() ?? throw new RuntimeException('There is no image to fix.');
        $fixes = $this->layouts->locate($keyframe, $render, $problems);
        $boxed = array_values(array_filter($fixes, fn(array $fix) => ! $fix['camera']));
        $camera = array_values(array_map(fn(array $fix) => $fix['problem'], array_filter($fixes, fn(array $fix) => $fix['camera'])));

        if ($boxed === []) {
            if ($camera === []) {
                throw new RuntimeException('Where to fix the keyframe could not be found.');
            }

            $render->setCustomProperty(Keyframe::CHECK_ISSUES, $camera)->save();

            return $render;
        }

        $repaired = $this->keep($keyframe, $this->logged($keyframe, fn() => $this->layouts->repair($keyframe, $render, $boxed)), true);
        $repaired->setCustomProperty(Keyframe::TWEAK_REQUEST, implode(' ', array_column($boxed, 'problem')))->setCustomProperty(Keyframe::TWEAK_FROM_CHECK, true);

        if ($camera !== []) {
            $repaired->setCustomProperty(Keyframe::CHECK_ISSUES, $camera);
        }

        $repaired->save();
        $this->checkRepair($keyframe, $repaired);

        return $repaired;
    }

    /**
     * Checks a fixed keyframe again, so a fix that brings a new problem, such
     * as an unnatural pose, becomes a note too. A failed check never fails the fix.
     */
    private function checkRepair(Keyframe $keyframe, Media $repaired): void
    {
        if (! Config::get('pipeline.keyframe_check')) {
            return;
        }

        try {
            if ($keyframe->position === 1 && $this->layouts->reads($keyframe)) {
                $this->readFirst($keyframe, $repaired);

                return;
            }

            $siblings = $keyframe->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $keyframe->shot);
            $check = $this->check($keyframe, $repaired, $this->referencesFor($keyframe, $siblings));

            if ($check !== null && ! $check['passes']) {
                $repaired->setCustomProperty(Keyframe::CHECK_ISSUES, [...(array) $repaired->getCustomProperty(Keyframe::CHECK_ISSUES, []), ...$check['problems']])->save();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Draws the start frame whole with the keyframe model, so the image model
     * composes it: the place from its picture, for how it looks, with the
     * camera chosen for the action, and the cast from theirs. Drawn to be
     * used straight away, it is read at once; an option is read once the
     * director chooses it.
     *
     * @param  array{title: string, description: string, spatial?: string|null}  $plan
     */
    private function paintFirst(Keyframe $keyframe, array $plan, bool $choose, string $extra): Media
    {
        $shot = $keyframe->shot;
        $setting = $this->settingFor($shot);
        $master = $this->masterFor($shot, $setting);
        $place = $keyframe->elements->first(fn(Element $element) => $element->type === ElementType::PLACE && $element->reference() !== null);
        $references = new KeyframeReferences(
            style: $this->styleReferenceFor($shot->project),
            elements: $keyframe->elements->all(),
            elementImages: $keyframe->elements
                ->filter(fn(Element $element) => $element->type !== ElementType::PLACE && $element->reference() !== null)
                ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
                ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
                ->values()
                ->all(),
            setting: $setting['image'] ?? null,
            settingLabel: $setting['label'] ?? '',
            settingIsMoment: $setting['moment'] ?? false,
            place: $setting === null && $place !== null ? $this->referenceFor($place->reference()) : null,
            master: $master !== null ? $this->referenceFor($master) : null,
            masterLabel: $this->masterLabel($shot),
        );
        $prompt = KeyframeImageBrief::for($shot, $plan, $references);
        $keyframe->forceFill(['prompt' => $prompt])->save();

        $render = $this->paint($keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose, labels: $references->labels());

        if ($choose) {
            $this->readFirst($keyframe, $render);
        }

        return $render;
    }

    /**
     * Draws the start frame of a shot that returns to an earlier camera on
     * that camera's last frame: the place stays exactly as it was, measured
     * in code like an end frame, and the people take the poses of the end
     * frame of the shot before.
     */
    private function paintReturn(Keyframe $keyframe, Media $view, string $prompt, KeyframeReferences $references, bool $choose, string $extra): Media
    {
        $keyframe->forceFill(['prompt' => $prompt])->save();
        $render = $this->paintOn($view, $keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose, labels: $references->labels());

        if ($choose && $this->layouts->reads($keyframe)) {
            $this->readFirst($keyframe, $render);
        }

        return $render;
    }

    /**
     * The earlier frame from the camera the start frame of this keyframe's shot returns to, when it has one.
     */
    private function viewFor(Keyframe $keyframe): ?Media
    {
        return $keyframe->position === 1 && $keyframe->shot->continuesPose() ? $keyframe->shot->returnFrame() : null;
    }

    /**
     * The master plate a start frame takes the place from, unless it is the setting image itself.
     *
     * @param  array{render: Media}|null  $setting
     */
    private function masterFor(Shot $shot, ?array $setting): ?Media
    {
        $master = $shot->masterPlate();

        return $master === null || $master->is($setting['render'] ?? null) ? null : $master;
    }

    private function masterLabel(Shot $shot): string
    {
        $master = $shot->masterShot();

        return $master === null ? '' : "the start frame of {$master->code()}, the first shot of this sequence";
    }

    /**
     * Whether the start frame is read, so the end frame is drawn from where everyone stands.
     */
    public function reads(Keyframe $keyframe): bool
    {
        return $this->layouts->reads($keyframe);
    }

    /**
     * Reads the start frame: where everyone stands is kept as the layout the end
     * keyframe is drawn from. Whoever stands in the wrong spot or does not
     * look like their picture becomes a note the director can have fixed or
     * dismiss; the image itself stays as it is.
     */
    public function readFirst(Keyframe $keyframe, Media $render): void
    {
        $reading = $this->layouts->read($keyframe, $render);
        $keyframe->forceFill(['layout' => $reading->layout()->toArray()])->save();

        if ($reading->problems() !== []) {
            $render->setCustomProperty(Keyframe::CHECK_ISSUES, [...(array) $render->getCustomProperty(Keyframe::CHECK_ISSUES, []), ...$reading->problems()])->save();
        }
    }

    /**
     * Reads where everyone stands in a keyframe drawn whole, so Select, Move
     * and Fix work on it and the keyframe after it knows where they were. A
     * failed reading leaves it to the first Select, which reads it then.
     */
    private function readLayout(Keyframe $keyframe, Media $render): void
    {
        try {
            $keyframe->forceFill(['layout' => $this->layouts->read($keyframe, $render)->layout()->toArray()])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Runs a drawing by the layout model and logs it on the keyframe, also when it fails.
     *
     * @template T of array{model: string, prompt: string}
     *
     * @param  callable(): T  $draw
     * @return T
     *
     * @throws Throwable when the layout model fails; the failure is logged on the keyframe first.
     */
    private function logged(Keyframe $keyframe, callable $draw): array
    {
        $directorId = $keyframe->shot->project->director_id;
        $started = hrtime(true);

        try {
            $result = $draw();
        } catch (Throwable $exception) {
            $keyframe->generations()->create(['director_id' => $directorId, 'kind' => 'image', 'provider' => 'replicate', 'model' => (string) Config::get('pipeline.models.keyframe_layout'), 'prompt' => (string) $keyframe->prompt, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'error' => $exception->getMessage()]);

            throw $exception;
        }

        $keyframe->generations()->create(['director_id' => $directorId, 'kind' => 'image', 'provider' => 'replicate', 'model' => $result['model'], 'prompt' => $result['prompt'], 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)]);

        return $result;
    }

    /**
     * Keeps a drawing by the layout model as a new render, chosen when `$choose`.
     *
     * @param  array{content: string, model: string, prompt: string, labels: list<string>}  $result
     */
    private function keep(Keyframe $keyframe, array $result, bool $choose): Media
    {
        $render = $keyframe
            ->addMediaFromString($result['content'])
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([Keyframe::SENT => ['model' => $result['model'], 'prompt' => $result['prompt'], 'images' => $result['labels']]])
            ->toMediaCollection(Keyframe::RENDERS);

        if ($choose) {
            $keyframe->forceFill(['render_id' => $render->id, 'render_error' => null])->save();
        }

        $keyframe->load('media');

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
            $keyframe->forceFill(['render_id' => $best->id, 'render_error' => null])->save();
        }

        return $best;
    }

    /**
     * The image a keyframe is drawn on and keeps everything of that it does
     * not change: the start frame for the end frame, or for the start frame the chosen place.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function baseFor(Keyframe $keyframe, Collection $siblings): ?Media
    {
        // Every still of a montage is drawn on its own.
        if ($keyframe->shot->drawsStandalone()) {
            return null;
        }

        return $keyframe->position > 1 ? $siblings->firstWhere('position', $keyframe->position - 1)?->render() : null;
    }

    /**
     * Looks at all keyframes of the shot together against its storyline. Null
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
     * The images the check of a render gets, each with what it is the
     * reference for, the render last; null when there is nothing to check
     * it against.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}|null
     */
    public function checkInput(Keyframe $keyframe, Media $render, KeyframeReferences $references): ?array
    {
        $shot = $keyframe->shot;

        if ($references->first !== null) {
            [$images, $roles] = $this->checkImages($references->first, $references);
        } elseif ($references->view !== null) {
            [$images, $roles] = $this->returnCheckImages($references);
        } elseif ($shot->drawsStandalone()) {
            [$images, $roles] = $this->stillCheckImages($references);
        } elseif ($references->setting !== null && $this->matchCut($keyframe, $references)) {
            [$images, $roles] = $this->matchCutCheckImages($references);
        } else {
            return null;
        }

        return [[...$images, $this->referenceFor($render)], [...$roles, 'the keyframe to check']];
    }

    /**
     * Whether the render is the start frame of a shot that continues another: the same instant as that shot's end frame, from a new camera.
     */
    private function matchCut(Keyframe $keyframe, KeyframeReferences $references): bool
    {
        return $references->first === null && $references->view === null && $references->settingIsMoment && $keyframe->position === 1 && $keyframe->shot->continuesPose();
    }

    /**
     * Has the checker compare a render with its references, each for its own
     * part: the start frame for the place and the people who are in it, and
     * their picture for how the people look. High issues count against the
     * render. Returns null
     * when the check itself fails, so a broken check never holds up the shot.
     *
     * @return array{passes: bool, problems: list<string>, fix: string}|null
     */
    private function check(Keyframe $keyframe, Media $render, KeyframeReferences $references): ?array
    {
        $input = $this->checkInput($keyframe, $render, $references);

        if ($input === null) {
            return null;
        }

        [$images, $roles] = $input;
        $checker = new KeyframeChecker($keyframe, $roles, matchCut: $this->matchCut($keyframe, $references), returnsToView: $references->view !== null);
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
     * The references a render is checked against, each with what it is the
     * reference for: the start frame for the place, the camera and the state
     * of objects, and the pictures of the people for who they are. The same
     * images it was drawn from.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}
     */
    private function checkImages(StoredImage $first, KeyframeReferences $references): array
    {
        $images = [$first];
        $roles = [match (true) {
            $references->firstShowsCast => 'the start frame of the shot: the reference for the place and the camera, for the state of objects and for how the people look',
            default => 'the start frame of the shot: the reference for the place and the camera; ' . implode(' and ', $references->castNames) . (count($references->castNames) > 1 ? ' are' : ' is') . ' not in it yet',
        }];

        foreach ($references->elementImages as $entry) {
            if ($entry['element']->type !== ElementType::PLACE) {
                $images[] = $entry['image'];
                $roles[] = "the picture of {$entry['element']->name}: the reference for how " . ($entry['element']->type === ElementType::PERSON ? 'this person' : 'this object') . ' looks';
            }
        }

        return [$images, $roles];
    }

    /**
     * The references the start frame of a shot that continues another is
     * checked against: the end frame it continues, for the instant it shows,
     * and the pictures of the people for who they are.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}
     */
    private function matchCutCheckImages(KeyframeReferences $references): array
    {
        $images = [$references->setting];
        $roles = [$references->master !== null
            ? "{$references->settingLabel}: the reference for the people's clothing, their poses and hand positions, and the objects and their state; the keyframe to check shows that same instant from another camera position"
            : "{$references->settingLabel}: the reference for the place, the light, the people's clothing, their poses and hand positions, and the objects and their state; the keyframe to check shows that same instant from another camera position"];

        if ($references->master !== null) {
            $images[] = $references->master;
            $roles[] = "{$references->masterLabel}, the master plate: the reference for the place, its walls, floor, doors, signs, fixed objects and light, seen from another camera position";
        }

        foreach ($references->elementImages as $entry) {
            if ($entry['element']->type !== ElementType::PLACE) {
                $images[] = $entry['image'];
                $roles[] = "the picture of {$entry['element']->name}: the reference for how " . ($entry['element']->type === ElementType::PERSON ? 'this person' : 'this object') . ' looks';
            }
        }

        return [$images, $roles];
    }

    /**
     * The references the start frame of a shot that returns to an earlier
     * camera is checked against: that camera's last frame for the place, the
     * camera and the framing, the end frame of the shot before for the
     * poses, and the pictures of the people for who they are.
     *
     * @return array{0: list<StoredImage>, 1: list<string>}
     */
    private function returnCheckImages(KeyframeReferences $references): array
    {
        $images = [$references->view];
        $roles = ["{$references->viewLabel}, from the same camera: the reference for the place, the camera and the framing, which must match exactly" . ($references->setting === null ? ', and for the people\'s clothing, poses and hand positions and the objects and their state' : '')];

        if ($references->setting !== null) {
            $images[] = $references->setting;
            $roles[] = "{$references->settingLabel}: the reference for the people's clothing, their poses and hand positions, and the objects and their state, from another camera position";
        }

        foreach ($references->elementImages as $entry) {
            if ($entry['element']->type !== ElementType::PLACE) {
                $images[] = $entry['image'];
                $roles[] = "the picture of {$entry['element']->name}: the reference for how " . ($entry['element']->type === ElementType::PERSON ? 'this person' : 'this object') . ' looks';
            }
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
     * sets with a reference image, and the start frame for the end frame.
     *
     * @param  Collection<int, Keyframe>  $siblings
     */
    public function referencesFor(Keyframe $keyframe, Collection $siblings): KeyframeReferences
    {
        $elements = $keyframe->elements->values();
        // A still of a montage has its own place: it is drawn like a start frame, from the style sheet and the pictures.
        $standalone = $keyframe->shot->drawsStandalone();
        $firstKeyframe = ! $standalone && KeyframeImageBrief::usesFirstKeyframe($keyframe->position) ? $siblings->firstWhere('position', 1) : null;
        $first = $firstKeyframe?->render();

        // Who the base image does not show yet: they are added when the keyframe is drawn on top of it.
        $people = $elements->filter(fn(Element $element) => $element->type === ElementType::PERSON);
        $missing = $firstKeyframe === null || $first === null
            ? collect()
            : $people->reject(fn(Element $person) => $firstKeyframe->elements->contains($person))->values();

        // Drawn from scratch, the start frame can play in the setting of another shot: the instant the shot before ends with, or for an insert only its place.
        $setting = $first === null && (! $standalone || $keyframe->shot->isInsert()) ? $this->settingFor($keyframe->shot) : null;
        // Back at an earlier camera, the start frame is drawn on its last frame; when that is the frame it continues, the poses are in it already.
        $view = $first === null ? $this->viewFor($keyframe) : null;
        $returnShot = $view !== null ? $keyframe->shot->returnShot() : null;
        $setting = $view !== null && $view->is($setting['render'] ?? null) ? null : $setting;
        // Elsewhere in a sequence, the place comes from the master plate, never from a copy of a copy.
        $master = $first === null && $view === null && ! $keyframe->shot->isMontage() && ! $keyframe->shot->isPresenter() ? $this->masterFor($keyframe->shot, $setting) : null;

        // Drawn on top of an image, or in the place of a master plate, the place comes from that image; only who and what is pictured stays.
        $placeFromImage = $first !== null || $view !== null || $master !== null;
        $elementImages = $elements
            ->filter(fn(Element $element) => $element->reference() !== null && (! $placeFromImage || $element->type !== ElementType::PLACE))
            ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();

        return new KeyframeReferences(
            style: $first === null && $view === null ? $this->styleReferenceFor($keyframe->shot->project) : null,
            elements: $elements->all(),
            elementImages: $elementImages,
            first: $first ? $this->referenceFor($first) : null,
            firstShowsCast: $missing->isEmpty(),
            castNames: $missing->pluck('name')->all(),
            setting: $setting['image'] ?? null,
            settingLabel: $setting['label'] ?? '',
            settingIsMoment: $setting['moment'] ?? false,
            view: $view !== null ? $this->referenceFor($view) : null,
            viewLabel: $returnShot !== null ? "the end frame of {$returnShot->code()}" : '',
            master: $master !== null ? $this->referenceFor($master) : null,
            masterLabel: $master !== null ? $this->masterLabel($keyframe->shot) : '',
        );
    }

    /**
     * The image of the setting the shot takes from another shot, with what it
     * is and whether it is an instant to continue or only a place. A shot
     * that renders in its sequence has none: its start frame is the start of
     * its own act, and the master plate gives it only the place.
     *
     * @return array{image: StoredImage, render: Media, label: string, moment: bool}|null
     */
    public function settingFor(Shot $shot): ?array
    {
        if ($shot->rendersInSequence()) {
            return null;
        }

        $from = $shot->settingFrom();
        $render = $shot->settingRender();

        if ($from === null || $render === null) {
            return null;
        }

        $source = $from['shot'];

        $label = match (true) {
            ! $from['chosen'] => "the end frame of {$source->code()}, the shot before",
            $from['keyframe'] === Shot::LAST_KEYFRAME => "the end frame of {$source->code()}",
            $from['keyframe'] === 0 => "the place of {$source->code()}",
            default => "keyframe {$from['keyframe']} of {$source->code()}",
        };

        return ['image' => $this->referenceFor($render), 'render' => $render, 'label' => $label, 'moment' => $from['keyframe'] !== 0];
    }
}
