<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\CorrectionSource;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use App\Support\Images\OpenRouterImageClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
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

        // Options for keyframe 1 are judged by the director; every keyframe that is used straight away is checked once.
        if (! $choose || ! Config::get('pipeline.keyframe_check')) {
            return $render;
        }

        // The keyframe stays busy while it is checked, so the director sees why it takes longer.
        $keyframe->forceFill(['rendering' => true, 'render_stage' => Keyframe::STAGE_CHECKING])->save();
        $mustShow = isset($plan['must_show']) ? (string) $plan['must_show'] : null;
        $check = $this->check($keyframe, $render, $references, $mustShow);

        if ($check === null || $check['passes'] || trim($check['fix']) === '') {
            $keyframe->forceFill(['rendering' => false, 'render_stage' => null])->save();

            return $render;
        }

        $keyframe->forceFill(['render_stage' => Keyframe::STAGE_FIXING, 'render_note' => implode(' ', $check['problems'])])->save();

        try {
            $redraw = $this->paint($keyframe, $prompt . "\nCorrect these mistakes from an earlier attempt: " . $check['fix'], $references->images(), $choose);
        } finally {
            $keyframe->forceFill(['rendering' => false, 'render_stage' => null, 'render_note' => null])->save();
        }

        $redraw->setCustomProperty(Keyframe::CHECK_PROBLEMS, $check['problems'])->save();
        RecordCorrection::record($shot->project, CorrectionSource::CHECK, implode(' ', $check['problems']), $shot, $keyframe, RecordCorrection::keyframeContext($keyframe));

        // When the point of the keyframe was missing, look once more: if it still is, say so instead of passing silently.
        if (filled($mustShow) && ! $check['must_show_visible']) {
            $keyframe->forceFill(['rendering' => true, 'render_stage' => Keyframe::STAGE_CHECKING])->save();
            $again = $this->check($keyframe, $redraw, $references, $mustShow);
            $keyframe->forceFill(['rendering' => false, 'render_stage' => null])->save();

            if ($again !== null && ! $again['must_show_visible']) {
                $redraw->setCustomProperty(Keyframe::CHECK_WARNING, $mustShow)->save();
            }
        }

        $keyframe->load('media');

        return $redraw;
    }

    /**
     * Looks at all keyframes of the shot together against its takeaway. Null
     * when the review itself fails; a review never holds up the shot.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{clear: bool, notes: list<string>}|null
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

        return [
            'clear' => (bool) ($result['clear'] ?? true),
            'notes' => array_values(array_filter(array_map('strval', (array) ($result['notes'] ?? [])))),
        ];
    }

    /**
     * Has the checker look at a render against the plan, keyframe 1 and the
     * cast and sets. Returns null when the check itself fails, so a broken
     * check never holds up the shot.
     *
     * @return array{passes: bool, must_show_visible: bool, problems: list<string>, fix: string}|null
     */
    private function check(Keyframe $keyframe, Media $render, KeyframeReferences $references, ?string $mustShow = null): ?array
    {
        $pictured = array_map(fn(array $entry) => $entry['element'], $references->elementImages);
        $checker = new KeyframeChecker($keyframe, $references->first !== null, $pictured, $mustShow);
        $images = array_values(array_filter([
            $this->referenceFor($render),
            $references->first,
            ...array_map(fn(array $entry) => $entry['image'], $references->elementImages),
        ]));
        $model = (string) Config::get('pipeline.models.text');
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

        return [
            'passes' => (bool) ($result['passes'] ?? true),
            'must_show_visible' => (bool) ($result['must_show_visible'] ?? true),
            'problems' => array_values(array_filter(array_map('strval', (array) ($result['problems'] ?? [])))),
            'fix' => (string) ($result['fix'] ?? ''),
        ];
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
