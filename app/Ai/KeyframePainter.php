<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Briefs\KeyframeImageBrief;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Support\Images\OpenRouterImageClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Generates one image for a keyframe, logs the generation with the prompt it
 * was sent, keeps the result as a new render and makes it the chosen one.
 */
class KeyframePainter
{
    /**
     * With `$choose` off the render is only added as a version, for options the director picks from later.
     * The image model defaults to the keyframe model; tweaks pass the edit model.
     *
     * @param  array<int, StoredImage>  $attachments
     *
     * @throws Throwable when the image model fails; the failure is logged on the keyframe first.
     */
    public function paint(Keyframe $keyframe, string $prompt, array $attachments = [], bool $choose = true, ?string $model = null): Media
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

        $render = $keyframe
            ->addMediaFromString($result['content'])
            ->usingFileName("keyframe-{$keyframe->position}.{$extension}")
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
        ];

        $references = $this->referencesFor($keyframe, $siblings);
        $prompt = KeyframeImageBrief::for($shot, $plan, $references);

        $keyframe->forceFill(['prompt' => $prompt])->save();

        $render = $this->paint($keyframe, $extra === '' ? $prompt : $prompt . "\n" . $extra, $references->images(), $choose);
        $keyframe->load('media');

        return $render;
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
        $first = KeyframeImageBrief::usesFirstKeyframe($keyframe->position) ? $siblings->firstWhere('position', 1)?->render() : null;
        $previous = KeyframeImageBrief::usesPreviousKeyframe($keyframe->position)
            ? $siblings->firstWhere('position', $keyframe->position - 1)?->render()
            : null;

        $elementImages = $elements
            ->filter(fn(Element $element) => $element->reference() !== null)
            ->take((int) Config::get('pipeline.keyframes.max_element_references', 3))
            ->map(fn(Element $element) => ['element' => $element, 'image' => $this->referenceFor($element->reference())])
            ->values()
            ->all();

        return new KeyframeReferences(
            style: $this->styleReferenceFor($keyframe->shot->project),
            elements: $elements->all(),
            elementImages: $elementImages,
            first: $first ? $this->referenceFor($first) : null,
            previous: $previous ? $this->referenceFor($previous) : null,
        );
    }
}
