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
use App\Support\Images\BackgroundDrift;
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
 * Draws a later keyframe on the plate of the shot: the stager blocks where
 * each of the cast stands on the keyframe before, the depth of the place sets
 * how big they are there, and the layout model keeps the plate and draws the
 * boxes of the cast and of what changed. Without a plate it draws on the
 * keyframe before.
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
        private readonly BackgroundDrift $drift,
    ) {}

    /**
     * Whether keyframe 1 is read this way: a scene or close-up, with the layout model available.
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
     * @param  Media|null  $measured  the image the depth of the place is measured on: keyframe 1
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

        if ($measured !== null && ($grid = $this->depth->of($measured)) !== null) {
            foreach ($layout->cast as $member) {
                $old = $before->member($member['name']);

                if ($member['changed'] && $old !== null) {
                    $layout = $layout->withBox($member['name'], KeyframeLayout::box(DepthSizing::fit($member['box'], $old['box'], $grid)) ?? $member['box']);
                }
            }
        }

        // A later keyframe is drawn on the plate, so the place is the same in every keyframe; without one, on the keyframe before.
        $plate = $keyframe->position > 1 ? $this->plate($keyframe) : null;
        $images = $plate === null ? [$this->bytes($base)] : [$plate, $this->bytes($base)];
        $labels = match (true) {
            $plate !== null => ['The place without people, from this camera, the image that is kept', 'The keyframe before, where the people and changed things come from'],
            $keyframe->position > 1 => ['The keyframe before, the image that is kept'],
            default => ['The chosen place, the image that is kept'],
        };
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

        $prompt = $plate === null
            ? KeyframeLayoutBrief::for($keyframe, $layout, $before, $pictures, $props->all(), $extra)
            : KeyframeLayoutBrief::onPlate($keyframe, $layout, $before, $this->onPlate($siblings), $this->carried($siblings, $keyframe, $layout), $pictures, $props->all(), $extra);

        return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels, 'layout' => $layout];
    }

    /**
     * Reads keyframe 1 after it was drawn whole: where each of the cast stands,
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
        // Where they stood, the plate shows what is there; it comes after their picture.
        $withPlate = function () use ($keyframe, &$images, &$labels): ?int {
            if (($bytes = $this->plate($keyframe)) === null) {
                return null;
            }

            $images[] = $bytes;
            $labels[] = 'The place without people, from this camera';

            return count($images) - 1;
        };

        if ($visible === null) {
            $prompt = KeyframeLayoutBrief::moved($keyframe, $layout, $member['name'], null, plate: $withPlate());

            return [...$this->generate($prompt, $images, $keyframe), 'prompt' => $prompt, 'labels' => $labels, 'layout' => $layout->without($member['name'])];
        }

        $element = $keyframe->elements->first(fn(Element $element) => mb_strtolower($element->name) === mb_strtolower($member['name']));
        $picture = null;

        if (KeyframeLayout::cutOff($member['box']) && $element?->reference() !== null) {
            $picture = count($images);
            $images[] = $this->bytes($element->reference());
            $labels[] = "The picture of {$element->name}";
        }

        $prompt = KeyframeLayoutBrief::moved($keyframe, $layout, $member['name'], $visible, $picture, cutOff: $visible !== $box, plate: $withPlate());

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
            'aspect_ratio' => Config::get('pipeline.keyframes.layout.fixed_ratio') ? $keyframe->shot->aspectRatio()->value : 'auto',
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
     * The objects of the cast that the plate shows, where they stand in it:
     * everyone of keyframe 1 who is not a person.
     *
     * @param  Collection<int, Keyframe>  $siblings
     * @return array<string, Box>
     */
    private function onPlate(Collection $siblings): array
    {
        $first = $siblings->firstWhere('position', 1);
        $objects = $first?->elements->filter(fn(Element $element) => $element->type !== ElementType::PERSON)->map(fn(Element $element) => mb_strtolower($element->name))->all() ?? [];
        $onPlate = [];

        foreach (KeyframeLayout::fromArray($first?->layout)->cast as $member) {
            if (in_array(mb_strtolower($member['name']), $objects, true)) {
                $onPlate[$member['name']] = $member['box'];
            }
        }

        return $onPlate;
    }

    /**
     * What earlier keyframes of the shot changed in the place and this one
     * does not change again, as it is now: the plate still shows it as it was.
     *
     * @param  Collection<int, Keyframe>  $siblings
     * @return list<array{thing: string, state: string, box: Box}>
     */
    private function carried(Collection $siblings, Keyframe $keyframe, KeyframeLayout $layout): array
    {
        $states = [];

        foreach ($siblings->sortBy('position') as $sibling) {
            if ($sibling->position > 1 && $sibling->position < $keyframe->position) {
                foreach (KeyframeLayout::fromArray($sibling->layout)->changes as $change) {
                    $states[mb_strtolower($change['thing'])] = $change;
                }
            }
        }

        foreach ($layout->changes as $change) {
            unset($states[mb_strtolower($change['thing'])]);
        }

        return array_values($states);
    }

    /**
     * The place of the shot without people, from the camera of keyframe 1:
     * made once from keyframe 1 by taking every person out, and kept on the
     * shot until keyframe 1 changes. A keyframe 1 without people is the plate
     * itself. A plate whose background moved from keyframe 1 is not kept: it
     * is tried once more, and without a good one there is none. Null when
     * keyframe 1 has no image or layout yet.
     */
    public function plate(Keyframe $keyframe): ?string
    {
        $shot = $keyframe->shot;
        $first = $keyframe->position === 1 ? $keyframe : $shot->keyframes()->with(['media', 'elements.media'])->where('position', 1)->first();
        $render = $first?->render();

        if ($first === null || $render === null || $first->layout === null) {
            return null;
        }

        $stored = $shot->getFirstMedia(Shot::PLATE);

        if ($stored !== null && $stored->getCustomProperty(Shot::PLATE_FROM) === $render->id) {
            return $this->bytes($stored);
        }

        $layout = KeyframeLayout::fromArray($first->layout);
        $people = $first->elements
            ->filter(fn(Element $element) => $element->type === ElementType::PERSON && $layout->member($element->name) !== null)
            ->map(fn(Element $element) => $element->name)
            ->values()
            ->all();

        if ($people === []) {
            $this->keepPlate($shot, $render, $content = $this->bytes($render));

            return $content;
        }

        $prompt = KeyframeLayoutBrief::plate($first, $layout, $people);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $started = hrtime(true);
            $content = $this->generate($prompt, [$this->bytes($render)], $first)['content'];
            $first->generations()->create(['director_id' => $shot->project->director_id, 'kind' => 'image', 'provider' => 'replicate', 'model' => (string) Config::get('pipeline.models.keyframe_layout'), 'prompt' => $prompt, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)]);

            $plate = $this->keepPlate($shot, $render, $content);

            if (! $this->drift->measure($render, $plate)['moved']) {
                return $content;
            }

            $plate->delete();
        }

        return null;
    }

    private function keepPlate(Shot $shot, Media $render, string $content): Media
    {
        return $shot->addMediaFromString($content)->usingFileName('plate.png')->withCustomProperties([Shot::PLATE_FROM => $render->id])->toMediaCollection(Shot::PLATE);
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
