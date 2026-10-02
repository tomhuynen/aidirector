<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Element;
use App\Models\ElementSuggestion;
use App\Models\Keyframe;
use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
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
    public function __construct(
        private readonly KeyframePainter $keyframes,
    ) {}

    /**
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    public function paint(Element $element, ?Keyframe $source = null): void
    {
        $project = $element->project;
        $style = $project->style;
        $render = $source?->render();

        $styleSheet = $render === null ? $this->keyframes->styleReferenceFor($project) : null;
        $attachments = match (true) {
            $render !== null => [$this->keyframes->referenceFor($render)],
            $styleSheet !== null => [$styleSheet],
            default => [],
        };

        $prompt = implode("\n", array_filter([
            "Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            $element->type->referenceStaging(),
            $element->promptLine(),
            $render !== null
                ? "The attached image is a keyframe in which {$element->name} appears. Draw {$element->name} exactly as it looks there: same shape, proportions, colours and details. Leave out everything else in that image."
                : ($attachments !== [] ? 'The attached image is the project\'s style reference sheet. Match its rendering style exactly; do not copy its subjects or layout.' : null),
            'No text, captions, logos or watermarks in the image.',
        ]));

        $this->generate($element, $prompt, $attachments);
    }

    /**
     * Changes the element's current reference image as the director asks,
     * keeping everything else as it is.
     *
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    public function edit(Element $element, string $instruction): void
    {
        $current = $element->reference() ?? throw new \RuntimeException('The element has no image to change yet.');

        $prompt = implode("\n", [
            'Edit the attached image.',
            "Change only this: {$instruction}",
            "It shows {$element->promptLine()}",
            'Keep everything else exactly as it is: the shapes, colours, style, framing and the plain background.',
            'No text, captions, logos or watermarks in the image.',
        ]);

        $this->generate($element, $prompt, [$this->keyframes->referenceFor($current)], $instruction);
    }

    /**
     * Generates the element's reference image with the edit model, the one
     * keyframe adjustments use, logs the call and stores the result.
     *
     * @param  list<\Laravel\Ai\Files\StoredImage>  $attachments
     *
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    private function generate(Element $element, string $prompt, array $attachments, ?string $request = null): void
    {
        $project = $element->project;
        $model = (string) Config::get('pipeline.models.image_edit');
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
            'No text, captions, logos or watermarks in the image.',
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
