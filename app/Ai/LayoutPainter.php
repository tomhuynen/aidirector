<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\KeyframeFixLocator;
use App\Ai\Agents\KeyframeReader;
use App\Ai\Agents\KeyframeStager;
use App\Ai\Briefs\KeyframeLayoutBrief;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Images\DepthSizing;
use App\Support\Images\PlaceDepth;
use App\Support\Images\ReplicateClient;
use App\Support\Shots\KeyframeLayout;
use App\Support\Shots\KeyframeReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Draws a keyframe on the image before it: the stager blocks where each of
 * the cast stands, the depth of the place sets how big they are there, and
 * the layout model keeps the image and draws the boxes that change.
 *
 * @phpstan-import-type Box from KeyframeLayout
 */
class LayoutPainter
{
    /** The most images the layout model takes: the image drawn on and the pictures of the cast. */
    private const int MAX_IMAGES = 10;

    public function __construct(
        private readonly ReplicateClient $replicate,
        private readonly PlaceDepth $depth,
    ) {}

    /**
     * Whether the start frame is read this way: a scene or close-up, with the layout model available.
     */
    public function reads(Keyframe $keyframe): bool
    {
        return ! $keyframe->shot->drawsStandalone() && ReplicateClient::configured();
    }

    /**
     * Whether a keyframe is drawn this way: a scene or close-up with an image to draw on, and the layout model available.
     */
    public function draws(Keyframe $keyframe, ?Media $base): bool
    {
        return $base !== null && ! $keyframe->shot->drawsStandalone() && ReplicateClient::configured();
    }

    /**
     * @param  Collection<int, Keyframe>  $siblings  the shot's keyframes, with media and elements loaded
     * @param  Media|null  $measured  the image the depth of the place is measured on: the start frame
     * @param  string  $extra  added to this drawing only, such as a change the director asked for
     * @return array{content: string, mime: string, model: string, prompt: string, labels: list<string>, layout: KeyframeLayout}
     */
    public function draw(Keyframe $keyframe, Media $base, Collection $siblings, ?Media $measured, string $extra = ''): array
    {
        $before = $keyframe->position > 1
            ? KeyframeLayout::fromArray($siblings->firstWhere('position', $keyframe->position - 1)?->layout)
            : new KeyframeLayout();
        $cast = $keyframe->elements->filter(fn(Element $element) => $element->type !== ElementType::PLACE)->values();
        $layout = $this->stage($keyframe, $base, $cast->all(), $before);

        // Whoever does not change keeps the box they had in the start frame, so their anchor is where they are.
        foreach ($layout->cast as $member) {
            if (! $member['changed'] && ($old = $before->member($member['name'])) !== null && $old['box'] !== $member['box']) {
                $layout = $layout->withBox($member['name'], $old['box']);
            }
        }

        if ($measured !== null && ($grid = $this->depth->of($measured)) !== null) {
            foreach ($layout->cast as $member) {
                $old = $before->member($member['name']);

                if ($member['changed'] && $old !== null) {
                    $layout = $layout->withBox($member['name'], KeyframeLayout::box(DepthSizing::fit($member['box'], $old['box'], $grid)) ?? $member['box']);
                }
            }
        }

        $images = [$this->bytes($base)];
        $labels = ['The start frame, the image that is kept'];
        $pictures = [];

        foreach ($layout->cast as $member) {
            $element = $cast->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower($member['name']));

            if ($member['changed'] && $element?->reference() !== null && count($images) < self::MAX_IMAGES) {
                $pictures[$member['name']] = count($images);
                $images[] = $this->bytes($element->reference());
                $labels[] = "The picture of {$element->name}";
            }
        }

        // What the cast holds is not staged on its own; its picture still shows how it looks.
        $props = $cast->filter(fn(Element $element) => $layout->member($element->name) === null && $element->type === ElementType::OBJECT && $element->reference() !== null)
            ->take(self::MAX_IMAGES - count($images))
            ->values();

        foreach ($props as $prop) {
            $images[] = $this->bytes($prop->reference());
            $labels[] = "The picture of {$prop->name}";
        }

        $prompt = KeyframeLayoutBrief::for($keyframe, $layout, $before, $pictures, $props->all(), $extra);
        $drawn = $this->generate($prompt, $images, $keyframe);

        return [...$drawn, 'prompt' => $prompt, 'labels' => $labels, 'layout' => $layout];
    }

    /**
     * Reads the start frame after it was drawn whole: where each of the cast stands,
     * and whether they stand and look as they should.
     */
    public function read(Keyframe $keyframe, Media $render): KeyframeReading
    {
        $cast = $keyframe->elements->filter(fn(Element $element) => $element->type !== ElementType::PLACE && $element->reference() !== null)->values()->all();

        if ($cast === []) {
            return new KeyframeReading();
        }

        $reader = new KeyframeReader($keyframe, $cast);
        $images = [ImageFile::fromStorage($render->getPathRelativeToRoot(), $render->disk)];

        foreach ($cast as $element) {
            $images[] = ImageFile::fromStorage($element->reference()->getPathRelativeToRoot(), $element->reference()->disk);
        }

        $answer = $this->ask($keyframe, $reader, $reader->promptFor(), $images, (string) Config::get('pipeline.models.keyframe_stager'));

        return KeyframeReading::fromArray($answer, array_map(fn(Element $element) => $element->name, $cast));
    }

    /**
     * Moves one of the cast in a keyframe to the box the director dragged
     * them to, sized by the depth of the place where they now stand, keeping
     * everything else of the image. A box partly past the edge of the frame
     * leaves them cut off by it; a box wholly past it takes them out of the
     * shot. Someone who was cut off is drawn from their picture, so the part
     * that was out of the frame is there when they come back in.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box  where they were dragged to; it may reach past the frame
     * @return array{content: string, mime: string, model: string, prompt: string, labels: list<string>, layout: KeyframeLayout}
     */
    public function move(Keyframe $keyframe, Media $render, ?Media $measured, string $name, array $box): array
    {
        $layout = KeyframeLayout::fromArray($keyframe->layout);
        $member = $layout->member($name) ?? throw new RuntimeException("{$name} is not in this keyframe.");

        if ($measured !== null && ($grid = $this->depth->of($measured)) !== null) {
            $box = DepthSizing::fit($box, $member['box'], $grid);
        }

        $visible = KeyframeLayout::box($box);
        $images = [$this->bytes($render)];
        $labels = ['This keyframe, the image that is kept'];

        if ($visible === null) {
            $prompt = KeyframeLayoutBrief::moved($keyframe, $layout, $member['name'], null);

            return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels, 'layout' => $layout->without($member['name'])];
        }

        $element = $keyframe->elements->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower($member['name']));
        $picture = null;

        if (KeyframeLayout::cutOff($member['box']) && $element?->reference() !== null) {
            $picture = count($images);
            $images[] = $this->bytes($element->reference());
            $labels[] = "The picture of {$element->name}";
        }

        $prompt = KeyframeLayoutBrief::moved($keyframe, $layout, $member['name'], $visible, $picture, cutOff: $visible !== $box);

        return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels, 'layout' => $layout->withBox($member['name'], $visible)];
    }

    /**
     * Changes some of the cast where they stand, as the director asked in the
     * chat or a check found, keeping everything else of the image. Whoever has
     * a picture is drawn again from it.
     *
     * @param  array<string, string>  $changes  per name of the cast, what changes about them
     * @return array{content: string, mime: string, model: string, prompt: string, labels: list<string>}
     */
    public function change(Keyframe $keyframe, Media $render, array $changes): array
    {
        $layout = KeyframeLayout::fromArray($keyframe->layout);
        $images = [$this->bytes($render)];
        $labels = ['This keyframe, the image that is kept'];
        $pictures = [];

        foreach (array_keys($changes) as $name) {
            if ($layout->member($name) === null) {
                throw new RuntimeException("{$name} is not in this keyframe.");
            }

            $element = $keyframe->elements->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower($name));

            if ($element?->reference() !== null && count($images) < self::MAX_IMAGES) {
                $pictures[$name] = count($images);
                $images[] = $this->bytes($element->reference());
                $labels[] = "The picture of {$element->name}";
            }
        }

        $prompt = KeyframeLayoutBrief::changed($keyframe, $layout, $changes, $pictures);

        return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels];
    }

    /**
     * @param  list<string>  $problems
     * @return list<array{problem: string, box: array{0: int, 1: int, 2: int, 3: int}, member: string|null, looks: bool, shows: string, camera: bool}>
     */
    public function locate(Keyframe $keyframe, Media $render, array $problems): array
    {
        $cast = $keyframe->elements->filter(fn(Element $element) => $element->type !== ElementType::PLACE)->values();
        $locator = new KeyframeFixLocator($keyframe, $problems, $cast->map(fn(Element $element) => $element->name)->all());
        $answer = $this->ask($keyframe, $locator, $locator->promptFor(), [ImageFile::fromStorage($render->getPathRelativeToRoot(), $render->disk)], (string) Config::get('pipeline.models.keyframe_stager'));

        return $locator->fixesFrom($answer);
    }

    /**
     * Fixes what a check found in a keyframe, each problem in the box the
     * locator found for it; when someone looks wrong, from their picture.
     *
     * @param  list<array{problem: string, box: array{0: int, 1: int, 2: int, 3: int}, member: string|null, looks: bool, shows: string, camera: bool}>  $fixes
     * @return array{content: string, mime: string, model: string, prompt: string, labels: list<string>}
     */
    public function repair(Keyframe $keyframe, Media $render, array $fixes): array
    {
        $cast = $keyframe->elements->filter(fn(Element $element) => $element->type !== ElementType::PLACE)->values();
        $images = [$this->bytes($render)];
        $labels = ['This keyframe, the image that is fixed'];
        $pictures = [];

        foreach ($fixes as $fix) {
            $element = ! $fix['looks'] ? null : $cast->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower((string) $fix['member']));

            if ($element?->reference() !== null && ! isset($pictures[$element->name]) && count($images) < self::MAX_IMAGES) {
                $pictures[$element->name] = count($images);
                $images[] = $this->bytes($element->reference());
                $labels[] = "The picture of {$element->name}";
            }
        }

        $prompt = KeyframeLayoutBrief::repaired($keyframe, KeyframeLayout::fromArray($keyframe->layout), $fixes, $pictures);

        return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels];
    }

    /**
     * Has the layout model draw the prompt on the images.
     *
     * @param  list<string>  $images
     * @return array{content: string, mime: string, model: string}
     */
    private function generate(string $prompt, array $images, Keyframe $keyframe): array
    {
        $model = (string) Config::get('pipeline.models.keyframe_layout');
        $input = [
            'prompt' => $prompt,
            'images' => array_map(fn(string $image) => ReplicateClient::dataUrl($image), $images),
            'aspect_ratio' => $keyframe->shot->aspectRatio()->value,
            'output_format' => 'png',
        ];

        if (! Config::get('pipeline.keyframes.layout.grounding', true)) {
            $input['grounding'] = false;
        }

        // The layout model now and then fails on its side; a second try usually draws it.
        $output = retry(2, fn() => $this->replicate->runModel($model, $input), 2000, fn(Throwable $exception) => $exception instanceof RuntimeException);
        $url = is_array($output) ? ($output[0] ?? null) : $output;

        if (! is_string($url)) {
            throw new RuntimeException('The layout model returned no image.');
        }

        return ['content' => $this->replicate->download($url), 'mime' => 'image/png', 'model' => $model];
    }

    /**
     * Has the stager block the keyframe on the image it is drawn on.
     *
     * @param  list<Element>  $cast
     */
    private function stage(Keyframe $keyframe, Media $base, array $cast, KeyframeLayout $before): KeyframeLayout
    {
        if ($cast === []) {
            return new KeyframeLayout();
        }

        $stager = new KeyframeStager($keyframe, $cast, $before);
        $answer = $this->ask($keyframe, $stager, $stager->promptFor(), [ImageFile::fromStorage($base->getPathRelativeToRoot(), $base->disk)], (string) Config::get('pipeline.models.keyframe_stager'));

        return $stager->layoutFrom($answer);
    }

    /**
     * Asks a vision agent about images and logs the call on the keyframe.
     *
     * @param  list<StoredImage>  $images
     * @return array<string, mixed>
     */
    private function ask(Keyframe $keyframe, KeyframeStager|KeyframeReader|KeyframeFixLocator $agent, string $prompt, array $images, string $model): array
    {
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($prompt, attachments: $images, provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            $keyframe->generations()->create([
                'director_id' => $keyframe->shot->project->director_id,
                'kind' => 'text',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $prompt,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $keyframe->generations()->create([
            'director_id' => $keyframe->shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        return $response->toArray();

    }

    private function bytes(Media $media): string
    {
        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    }
}
