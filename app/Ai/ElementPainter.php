<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Image;
use Throwable;

/**
 * Draws the reference image of a cast or set element, so later shots can
 * attach it. An element that appears in keyframe 1 is isolated from that
 * render; otherwise it is drawn from its description in the project style.
 */
class ElementPainter
{
    public function __construct(
        private readonly KeyframePainter $keyframes,
    ) {}

    /**
     * Gives every element in these keyframes that has no reference image yet
     * one. A failed element is logged and skipped; the keyframes still render.
     *
     * @param  Collection<int, Keyframe>  $siblings  with elements loaded
     */
    public function paintMissing(Collection $siblings, Keyframe $first): void
    {
        $missing = $siblings
            ->flatMap(fn(Keyframe $keyframe) => $keyframe->elements)
            ->unique('id')
            ->filter(fn(Element $element) => $element->reference() === null);

        foreach ($missing as $element) {
            $element->setRelation('project', $first->shot->project);
            $inFirst = $first->elements->contains('id', $element->id);

            try {
                $this->paint($element, $inFirst ? $first : null);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $siblings->each(fn(Keyframe $keyframe) => $keyframe->load('elements.media'));
    }

    /**
     * @throws Throwable when the image model fails; the failure is logged on the element first.
     */
    public function paint(Element $element, ?Keyframe $source = null): void
    {
        $project = $element->project;
        $style = $project->style;
        $render = $source?->render();
        $model = Config::get('pipeline.models.image');

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

        $element
            ->addMediaFromString($image->content())
            ->usingFileName('element-' . $element->sqid . match ($image->mime()) {
                'image/jpeg' => '.jpg',
                'image/webp' => '.webp',
                default => '.png',
            })
            ->toMediaCollection(Element::REFERENCE);
    }
}
