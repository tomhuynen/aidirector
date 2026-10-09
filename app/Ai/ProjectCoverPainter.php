<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Throwable;

/**
 * Draws the project's cover: poster-like key art with the people of the
 * cast as one lively group in a wide landscape built from one of its
 * places, with a few of its objects around them, in the project style. Every element is attached by its
 * reference image so the picture matches what the shots will use.
 */
class ProjectCoverPainter
{
    public const MAX_PEOPLE = 6;

    public const MAX_OBJECTS = 3;

    /**
     * A wide banner: the project page shows it full width at the height of
     * the project cards on the overview, and the cards crop its sides.
     */
    public const ASPECT_RATIO = '4:1';

    public function __construct(
        private readonly KeyframePainter $keyframes,
    ) {}

    /**
     * Whether there is anything to draw a group picture of.
     */
    public function canPaint(Project $project): bool
    {
        return $project->elements()->get()->contains(fn(Element $element) => $element->reference() !== null);
    }

    /**
     * @throws Throwable when the image model fails; the failure is logged on the project first.
     */
    public function paint(Project $project): void
    {
        $elements = $project->elements()->with('media')->get()->filter(fn(Element $element) => $element->reference() !== null);
        $people = $elements->where('type', ElementType::PERSON)->take(self::MAX_PEOPLE)->values();
        $place = $elements->where('type', ElementType::PLACE)->first();
        $objects = $elements->where('type', ElementType::OBJECT)->take(self::MAX_OBJECTS)->values();

        /** @var Collection<int, Element> $cast */
        $cast = $people->concat(array_filter([$place]))->concat($objects)->values();
        $styleSheet = $this->keyframes->styleReferenceFor($project);
        $style = $project->style ?? [];

        $attachments = $cast->map(fn(Element $element): StoredImage => ImageFile::fromStorage($element->reference()->getPathRelativeToRoot(), $element->reference()->disk))
            ->when($styleSheet !== null, fn(Collection $images) => $images->push($styleSheet))
            ->values()
            ->all();

        $listing = $cast->values()->map(fn(Element $element, int $index) => 'Attached image ' . ($index + 1) . ": {$element->promptLine()}")->join("\n");

        $prompt = implode("\n", array_filter([
            'Visual style: ' . ($style['look'] ?? '') . '. Medium: ' . ($style['medium'] ?? '') . '. Mood: ' . ($style['mood'] ?? '') . '. Palette: ' . ($style['palette'] ?? '') . '.',
            "Key art for the project \"{$project->title}\" as a very wide panoramic banner, the way a poster for an animated film presents its cast: the characters together as one lively group in the centre of a wide landscape. Keep the group in the middle third so it survives when the sides are cropped, and keep everyone's head well inside the frame.",
            $people->isNotEmpty()
                ? 'The people form one tight, overlapping group in the centre of the picture, at different depths: some in front, some behind, some leaning in. Each has a pose and expression that shows who they are, such as waving, pointing or holding their tools, and each stays fully recognisable. Never a row of people standing side by side.'
                : 'There are no people in this picture; the objects are the heroes in front of the landscape.',
            $place !== null
                ? "Behind and around the group stretches {$place->name} as a wide landscape with depth: a foreground, a middle ground and a far horizon with open sky, seen from a slightly low angle."
                : 'Behind the group stretches a wide landscape that fits the project, with depth and an open sky.',
            $objects->isNotEmpty() ? 'The objects belong to the scene around the group; some are held or used by the characters.' : null,
            'Let the composition, energy and light follow the visual style and mood: playful and bouncy for a cartoon look, calm and grounded for a serious one.',
            'Draw every person, place and object exactly as in its attached image: same faces, clothes, shapes and colours.',
            $listing,
            $styleSheet !== null ? 'The last attached image is the project\'s style reference sheet. Match its rendering style exactly; do not copy its subjects or layout.' : null,
            'No text, captions or watermarks in the image, and no logos or brand names except those already on the people and things in their pictures, kept exactly as they are.',
        ]));

        $model = Config::get('pipeline.models.image');
        $started = hrtime(true);

        try {
            $response = Image::of($prompt)
                ->size(self::ASPECT_RATIO)
                ->quality(Config::get('pipeline.image_quality'))
                ->attachments($attachments)
                ->timeout(180)
                ->generate('openrouter', $model);
        } catch (Throwable $exception) {
            $project->generations()->create([
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

        $project->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'image',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $image = $response->firstImage();

        $project
            ->addMediaFromString($image->content())
            ->usingFileName('cover-' . $project->sqid . match ($image->mime()) {
                'image/jpeg' => '.jpg',
                'image/webp' => '.webp',
                default => '.png',
            })
            ->toMediaCollection(Project::COVER);
    }
}
