<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\ElementType;
use App\Models\Element;
use App\Models\ElementSuggestion;
use App\Models\Keyframe;
use App\Models\Project;
use App\Support\Images\OpenRouterImageClient;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Throwable;

/**
 * Draws the reference image of a cast or set element, so later shots can
 * attach it. An element that appears in keyframe 1 is isolated from that
 * render; otherwise it is drawn from its description in the project style.
 * The director can change it afterwards. Element images use the edit model,
 * the same one that adjusts keyframes.
 */
class ElementPainter
{
    /**
     * Elements never carry text: a description is about how it looks, not words to print on it.
     */
    public const NO_TEXT = 'Never write any text on it or in the image: no letters, words, slogans, numbers, labels or logos, not even words from the description. Markings are plain shapes and colours.';

    public function __construct(
        private readonly KeyframePainter $keyframes,
    ) {}

    /**
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    /**
     * @param  list<Element>  $includes  elements of the project to draw into this one, such as an object a person holds
     */
    public function paint(Element $element, ?Keyframe $source = null, array $includes = []): void
    {
        $project = $element->project;
        $style = $project->style;
        $render = $source?->render();

        if ($render !== null) {
            $prompt = implode("\n", [
                "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
                $element->type->referenceStaging(),
                $element->promptLine(),
                "The attached image is a keyframe in which {$element->name} appears. Draw {$element->name} exactly as it looks there: same shape, proportions, colours and details. Leave out everything else in that image.",
                self::NO_TEXT,
            ]);

            $this->generate($element, $prompt, [$this->keyframes->referenceFor($render)], (string) Config::get('pipeline.models.image_edit'));

            return;
        }

        $styleSheet = $this->keyframes->styleReferenceFor($project);
        $anchor = $this->styleAnchorFor($element);
        // Queried, not loaded, so the element's media list is not cached before the new picture is added.
        $photo = $element->media()->where('collection_name', Element::PHOTO)->first();

        $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
        $attachments = [];
        $lines = [
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            $element->type->referenceStaging(),
            $element->promptLine(),
        ];

        if ($styleSheet !== null) {
            $lines[] = 'The ' . $ordinals[count($attachments)] . ' attached image is the project\'s style reference sheet. Match its rendering style exactly: the same medium, line work, shading, colours and level of detail. Do not copy its subjects or layout.';
            $attachments[] = $styleSheet;
        }

        if ($anchor !== null) {
            $lines[] = 'The ' . $ordinals[count($attachments)] . " attached image is {$anchor->name}, already drawn for this project. Draw {$element->name} in exactly the same style: the same proportions and head-to-body ratio, line work, shading and level of detail, so they look like they belong in the same film. Do not copy {$anchor->name}'s face, clothing or pose.";
            $attachments[] = $this->keyframes->referenceFor($anchor->reference());
        }

        if ($photo !== null && $element->type === ElementType::PERSON) {
            // The image model often refuses photos of real people, so a drawing of the person is made first.
            $drawing = $this->drawPerson($element, ImageFile::fromStorage($photo->getPathRelativeToRoot(), $photo->disk));
            $lines[] = 'The ' . $ordinals[count($attachments)] . " attached image is a drawing of {$element->name}. Take only who they are from it: face, hair, build and clothing. "
                . ($anchor !== null ? "Take the proportions, head size and body shape from {$anchor->name}, so {$element->name} stands like the rest of the cast," : 'Give them realistic adult proportions,')
                . ' and redraw them in the project style.';
            $attachments[] = $drawing;
        } elseif ($photo !== null) {
            $lines[] = 'The ' . $ordinals[count($attachments)] . " attached image is a photo of the real {$element->name}. Use it only for what it is: shapes, markings and colours. Do not copy its realism, lighting or proportions; those come from the style.";
            $attachments[] = ImageFile::fromStorage($photo->getPathRelativeToRoot(), $photo->disk);
        }

        $included = array_values(array_filter($includes, fn(Element $other) => $other->reference() !== null && ! $other->is($element)));

        if ($included !== []) {
            $lines[] = 'Show these together with ' . $element->name . ', as part of it, and nothing else: ' . implode(', ', array_map(fn(Element $other) => $other->name, $included)) . '.';

            foreach ($included as $other) {
                $lines[] = 'The ' . $ordinals[count($attachments)] . " attached image is {$other->name} ({$other->type->value}). Draw it exactly like it: same shape, proportions, colours and details.";
                $attachments[] = $this->keyframes->referenceFor($other->reference());
            }
        }

        $lines[] = self::NO_TEXT;

        $this->generate($element, implode("\n", $lines), $attachments, (string) Config::get('pipeline.models.image'));
    }

    /**
     * Turns a photo of a real person into a plain drawing of them with natural
     * proportions, which the image model then restyles. Logged on the element.
     *
     * @throws Throwable when the model fails; the failure is logged on the element first.
     */
    private function drawPerson(Element $element, StoredImage $photo): Base64Image
    {
        $model = (string) Config::get('pipeline.models.photo_drawing');
        $prompt = implode("\n", [
            'Turn the person from the attached photo into a simple 2D illustration of them with realistic adult proportions: a normal-sized head, about one eighth of their height, and a natural body. Not a caricature, no big head, no chibi.',
            "Who: {$element->promptLine()}",
            'Keep their face, hair, build and clothing recognisable. Show only them, full body, standing upright in a neutral pose, facing the viewer, on a plain light grey background. No other people or objects.',
            self::NO_TEXT,
        ]);
        $started = hrtime(true);

        try {
            $image = app(OpenRouterImageClient::class)->generate($model, $prompt, [$photo], '1:1');
        } catch (Throwable $exception) {
            $this->logGeneration($element, $model, $prompt, $started, error: $exception->getMessage());

            throw $exception;
        }

        $this->logGeneration($element, $model, $prompt, $started, usage: array_filter(['cost' => $image['cost']], fn(mixed $value) => $value !== null));

        return ImageFile::fromBase64(base64_encode($image['content']), $image['mime']);
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function logGeneration(Element $element, string $model, string $prompt, int $started, array $usage = [], ?string $error = null): void
    {
        $element->generations()->create(array_filter([
            'director_id' => $element->project->director_id,
            'kind' => 'image',
            'provider' => 'openrouter',
            'model' => $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $usage === [] ? null : $usage,
            'error' => $error,
        ], fn(mixed $value) => $value !== null));
    }

    /**
     * An element already drawn for the project to match the style of, the
     * earliest of the same type, or of any type when there is none. Chosen
     * automatically, so the cast keeps one look.
     */
    private function styleAnchorFor(Element $element): ?Element
    {
        $others = $element->project->elements()
            ->whereKeyNot($element->getKey())
            ->with('media')
            ->reorder('id')
            ->get()
            ->filter(fn(Element $other) => $other->reference() !== null);

        return $others->firstWhere('type', $element->type) ?? $others->first();
    }

    /**
     * Changes the element's current reference image as the director asks,
     * keeping everything else as it is.
     *
     * @param  list<Element>  $includes  elements of the project to draw into the picture, each from its own picture
     *
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    public function edit(Element $element, string $instruction, array $includes = []): void
    {
        $current = $element->reference() ?? throw new \RuntimeException('The element has no image to change yet.');
        $included = array_values(array_filter($includes, fn(Element $other) => $other->reference() !== null && ! $other->is($element)));
        $ordinals = ['second', 'third', 'fourth'];
        $attachments = [$this->keyframes->referenceFor($current)];

        $lines = [
            $included === [] ? 'Edit the attached image.' : 'Edit the first attached image.',
            "Change only this: {$instruction}",
            "It shows {$element->promptLine()}",
        ];

        foreach ($included as $index => $other) {
            $lines[] = 'The ' . $ordinals[$index] . " attached image is {$other->name} ({$other->type->value}). Draw it into the picture exactly like it: same shape, proportions, colours and details, in the same style as the first image.";
            $attachments[] = $this->keyframes->referenceFor($other->reference());
        }

        $lines[] = 'Keep everything else exactly as it is: the shapes, colours, style, framing and the plain background.';
        $lines[] = self::NO_TEXT;

        $this->generate($element, implode("\n", $lines), $attachments, (string) Config::get('pipeline.models.image_edit'), $instruction);
    }

    /**
     * Generates the element's reference image with the given model, logs the
     * call and stores the result.
     *
     * @param  list<\Laravel\Ai\Files\Image>  $attachments
     *
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    private function generate(Element $element, string $prompt, array $attachments, string $model, ?string $request = null): void
    {
        $project = $element->project;
        $started = hrtime(true);

        try {
            $response = Image::of($prompt)
                ->size('1:1')
                ->quality(Config::get('pipeline.image_quality'))
                ->attachments($attachments)
                ->timeout(180)
                ->generate('openrouter', $model);

            $image = app(ImageReplies::class)->firstImage($response);
        } catch (Throwable $exception) {
            $element->generations()->create([
                'director_id' => $project->director_id,
                'kind' => 'image',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $prompt,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $element->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'image',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $version = $element
            ->addMediaFromString($image->content())
            ->usingFileName('element-' . $element->sqid . match ($image->mime()) {
                'image/jpeg' => '.jpg',
                'image/webp' => '.webp',
                default => '.png',
            })
            ->withCustomProperties(array_filter([Element::CHANGE_REQUEST => $request]))
            ->toMediaCollection(Element::REFERENCE);

        $element->forceFill(['reference_id' => $version->id])->save();
    }

    /**
     * Renders an element suggestion from the intake chat in the project
     * style. A suggestion based on an uploaded photo restyles that photo, so
     * the real shapes and markings survive; otherwise it is drawn from its
     * description with the style sheet as reference.
     *
     * @throws Throwable when the image model fails; the failure is logged on the suggestion first.
     */
    public function paintSuggestion(ElementSuggestion $suggestion): void
    {
        $round = $suggestion->round;
        $project = $round->project;
        $style = $project->style ?? [];
        $model = Config::get('pipeline.models.image');
        $photo = $suggestion->sourcePhoto;
        $styleSheet = $this->keyframes->styleReferenceFor($project);

        $attachments = array_values(array_filter([
            $photo === null ? null : ImageFile::fromStorage(
                $photo->hasGeneratedConversion(Project::REFERENCE) ? $photo->getPathRelativeToRoot(Project::REFERENCE) : $photo->getPathRelativeToRoot(),
                $photo->hasGeneratedConversion(Project::REFERENCE) ? ($photo->conversions_disk ?? $photo->disk) : $photo->disk,
            ),
            $styleSheet,
        ]));

        $prompt = implode("\n", array_filter([
            'Visual style: ' . ($style['look'] ?? '') . '. Medium: ' . ($style['medium'] ?? '') . '. Mood: ' . ($style['mood'] ?? '') . '. Palette: ' . ($style['palette'] ?? '') . '.',
            $round->type->referenceStaging(),
            "{$suggestion->name} ({$round->type->value}): {$suggestion->description}",
            match (true) {
                $photo !== null && $styleSheet !== null => "The first attached image is a photo of the real {$suggestion->name}: keep its shapes, proportions, markings and colours. The second attached image is the project's style reference sheet: draw it in exactly that rendering style; do not copy the sheet's subjects or layout.",
                $photo !== null => "The attached image is a photo of the real {$suggestion->name}: keep its shapes, proportions, markings and colours, drawn in the visual style above.",
                $styleSheet !== null => 'The attached image is the project\'s style reference sheet. Match its rendering style exactly; do not copy its subjects or layout.',
                default => null,
            },
            self::NO_TEXT,
        ]));

        $started = hrtime(true);

        try {
            $response = Image::of($prompt)
                ->size('1:1')
                ->quality(Config::get('pipeline.image_quality'))
                ->attachments($attachments)
                ->timeout(180)
                ->generate('openrouter', $model);
        } catch (Throwable $exception) {
            $suggestion->generations()->create([
                'director_id' => $project->director_id,
                'kind' => 'image',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $prompt,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $suggestion->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'image',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $image = $response->firstImage();

        $suggestion
            ->addMediaFromString($image->content())
            ->usingFileName('suggestion-' . $suggestion->sqid . match ($image->mime()) {
                'image/jpeg' => '.jpg',
                'image/webp' => '.webp',
                default => '.png',
            })
            ->toMediaCollection(ElementSuggestion::RENDER);
    }
}
