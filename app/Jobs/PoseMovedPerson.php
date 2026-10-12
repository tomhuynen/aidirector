<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\MovedPersonDescriber;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Jobs\Concerns\FollowsPlan;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\PlaceObjects;
use App\Support\Images\TopLayerCutout;
use App\Support\Media\LocalMediaFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * After the director moved a person in a keyframe by hand, the description
 * and the plan are made true to the image and the shot is reviewed. When the
 * director asks to fit the person to the new spot, the image model first
 * redraws their pose, gaze and gesture on a tight crop around them, and only
 * the person is put back, with their feet where they were put: the rest of
 * the image cannot drift. A redraw that moved them off their spot or shifted
 * the place is drawn again.
 */
#[DeleteWhenMissingModels]
class PoseMovedPerson implements ShouldQueue
{
    use FollowsPlan;
    use MarksRenderFailures;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box  where the person was put, as shares of the image
     * @param  bool  $redraw  whether the pose is fitted to the new spot; otherwise only the description follows the image
     */
    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
        public readonly array $box,
        public readonly bool $redraw = true,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    public function handle(KeyframePainter $painter, TopLayerCutout $cutout, BackgroundDrift $drift): void
    {
        $keyframe = $this->keyframe->load(['shot.project', 'media']);
        $shot = $keyframe->shot;

        if (! $this->redraw) {
            $this->describe($keyframe, $painter);
            ReviewShot::after($shot);

            return;
        }

        $plate = $painter->baseFor($keyframe, $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot));
        $moved = $keyframe->render();

        // The place or a later state of it, such as with the door closed.
        if ($plate === null || ! $painter->isPlace($plate) || $moved === null) {
            throw new RuntimeException('The keyframe is not drawn on a place.');
        }

        $kept = null;
        $attempts = max(1, (int) Config::get('pipeline.keyframes.move.attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $render = $this->redrawAround($painter, $cutout, $keyframe, $moved, $plate);
            $still = $drift->measure($plate, $render)['moved'] === false;
            $stayed = $this->stayedPut($cutout, $plate, $render);

            if ($still && $stayed) {
                $kept?->delete();
                $kept = $render;
                break;
            }

            $kept === null ? $kept = $render : $render->delete();
        }

        $kept->setCustomProperty(Keyframe::TWEAK_REQUEST, $this->instruction)->save();
        $keyframe->forceFill(['render_id' => $kept->id])->save();

        $this->describe($keyframe->refresh()->load('media'), $painter);
        $keyframe->forceFill(['rendering' => false, 'render_error' => null])->save();

        ReviewShot::after($shot);
    }

    public function failed(?Throwable $exception): void
    {
        // The moved version stays the keyframe's image; the plan still follows it before the shot is reviewed.
        $this->markRenderFailed($this->keyframe, __('The person is moved, but the pose could not be redrawn. Adjust the image to try again.'), $exception);

        try {
            $keyframe = $this->keyframe->fresh(['shot.project', 'media']);

            if ($keyframe !== null) {
                $this->describe($keyframe, app(KeyframePainter::class));
                ReviewShot::after($keyframe->shot);
            }
        } catch (Throwable $describing) {
            report($describing);
        }
    }

    /**
     * The pose redrawn on a tight crop around the person; only the person is put back into the moved version.
     */
    private function redrawAround(KeyframePainter $painter, TopLayerCutout $cutout, Keyframe $keyframe, Media $moved, Media $plate): Media
    {
        $files = new LocalMediaFiles();
        $image = new \Imagick($files->path($moved));
        $crop = $cutout->cropAround($this->box, $image->getImageWidth(), $image->getImageHeight(), (float) Config::get('pipeline.keyframes.move.crop'));
        $image->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
        $image->setImagePage(0, 0, 0, 0);
        $image->setImageFormat('png');

        $stored = 'tmp/pose/' . Str::random(16) . '.png';
        Storage::disk('local')->put($stored, $image->getImageBlob());

        try {
            $redrawn = $painter->paint(
                $keyframe,
                KeyframeImageBrief::tweak(trim($this->instruction . ' ' . $this->directions($keyframe, $plate, $crop, $image->getImageWidth(), $image->getImageHeight()))),
                [ImageFile::fromStorage($stored, 'local')],
                false,
                (string) Config::get('pipeline.models.keyframe_edit'),
                ['A crop around the person moved by hand, the image that is edited'],
            );
        } finally {
            Storage::disk('local')->delete($stored);
        }

        $output = storage_path('app/tmp/pose-' . Str::random(12) . '.png');
        @mkdir(dirname($output), 0755, true);
        $mask = $moved->getCustomProperty(Keyframe::TOP_LAYER_MASK);
        $finder = fn(string $path) => $painter->movableMasks($keyframe, $plate, (string) file_get_contents($path))['all'];
        $cutout->withTopLayer(is_string($mask) ? base64_decode($mask) : null)->withFinder($finder)->pastePerson($files->path($plate), $files->path($moved), $files->path($redrawn), $crop, $this->box, $output);
        $sent = $redrawn->getCustomProperty(Keyframe::SENT);
        $redrawn->delete();

        return $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            // Still a composite, so a change of the place later keeps it; what can be moved in it is found again on the next click.
            ->withCustomProperties([Keyframe::SENT => $sent, Keyframe::MOVED_BOX => $this->box, ...array_filter([Keyframe::COMPOSITED => $moved->getCustomProperty(Keyframe::COMPOSITED)])])
            ->toMediaCollection(Keyframe::RENDERS);
    }

    /**
     * Which way each object the instruction names lies from the person's head, in the crop's own terms, such as
     * "the ash bin is toward the lower right of them, beyond the right edge of the image": the crop does not
     * show the whole keyframe, so the model is told where to look instead.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     */
    private function directions(Keyframe $keyframe, Media $plate, array $crop, int $width, int $height): string
    {
        $placeObjects = app(PlaceObjects::class);
        $headX = ($this->box['x'] + $this->box['width'] / 2) * $width;
        $headY = ($this->box['y'] + $this->box['height'] * 0.08) * $height;
        $lines = [];

        foreach ($keyframe->load('elements.media')->elements->filter(fn(Element $element) => $element->type === ElementType::OBJECT) as $object) {
            if (! str_contains(mb_strtolower($this->instruction), mb_strtolower($object->name)) || ! $placeObjects->isIn($plate, $object)) {
                continue;
            }

            $centre = $this->centreOf($placeObjects->maskIn($plate, $object));

            if ($centre === null) {
                continue;
            }

            $dx = $centre[0] * $width - $headX;
            $dy = $centre[1] * $height - $headY;
            $side = $dx > 0 ? 'right' : 'left';
            $vertical = $dy > abs($dx) * 0.4 ? 'lower ' : ($dy < -abs($dx) * 0.4 ? 'upper ' : '');
            $x = $centre[0] * $width - $crop['x'];
            $outside = $x > $crop['width'] || $x < 0 ? ", beyond the {$side} edge of the image" : '';
            $lines[] = "The {$object->name} is toward the {$vertical}{$side} of them{$outside}: they turn their head and eyes that way, toward the {$vertical}{$side}.";
        }

        return $lines === [] ? '' : implode(' ', [...$lines, 'Their feet stay exactly where they are.']);
    }

    /**
     * The centre of a white-on-black mask, as shares of its width and height.
     *
     * @return array{float, float}|null
     */
    private function centreOf(?string $mask): ?array
    {
        if ($mask === null) {
            return null;
        }

        $image = new \Imagick();
        $image->readImageBlob($mask);
        [$w, $h] = [$image->getImageWidth(), $image->getImageHeight()];
        $pixels = $image->exportImagePixels(0, 0, $w, $h, 'I', \Imagick::PIXEL_CHAR);
        [$sx, $sy, $n] = [0, 0, 0];

        foreach ($pixels as $i => $value) {
            if ($value > 127) {
                $sx += $i % $w;
                $sy += intdiv($i, $w);
                $n++;
            }
        }

        return $n === 0 ? null : [($sx / $n + 0.5) / $w, ($sy / $n + 0.5) / $h];
    }

    /**
     * Rewrites the description, the storyline and when needed the title to match the image;
     * when the takeaway no longer comes across, that is kept as a warning on the image.
     */
    private function describe(Keyframe $keyframe, KeyframePainter $painter): void
    {
        $shot = $keyframe->shot;
        $render = $keyframe->render();
        $describer = new MovedPersonDescriber($keyframe);
        $model = (string) Config::get('pipeline.models.text');

        if ($render === null) {
            return;
        }

        /** @var StructuredAgentResponse $response */
        $response = $describer->prompt($describer->promptFor(), attachments: [$painter->referenceFor($render)], provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $describer->promptFor(),
            'usage' => $response->usage->toArray(),
        ]);

        $description = trim((string) ($response['description'] ?? ''));
        $warning = trim((string) ($response['warning'] ?? ''));
        $title = trim((string) ($response['title'] ?? ''));
        $storyline = trim((string) ($response['storyline'] ?? ''));
        $spatial = trim((string) ($response['spatial'] ?? ''));

        if ($description !== '') {
            $keyframe->forceFill([...array_filter(['description' => $description, 'title' => $title]), 'spatial' => $spatial !== '' ? $spatial : null])->save();
            $shot->updatePlannedKeyframe($keyframe->position, array_filter(['title' => $title, 'description' => $description, 'spatial' => $spatial]), $spatial === '' ? ['spatial', 'prompt'] : ['prompt']);
        }

        // The storyline follows small moves too, so the review and the video prompt see one story.
        if ($storyline !== '' && $shot->chosen_storyline !== null) {
            $shot->forceFill(['chosen_storyline' => [...$shot->chosen_storyline, 'storyline' => $storyline]])->save();
        }

        $render->setCustomProperty(Keyframe::MOVE_WARNING, $warning !== '' ? $warning : null)->save();
    }

    /**
     * Whether the person in the redraw is still about where they were put and about as large.
     */
    private function stayedPut(TopLayerCutout $cutout, Media $plate, Media $render): bool
    {
        $files = new LocalMediaFiles();
        $found = $cutout->locate($files->path($plate), $files->path($render), $this->box);

        if ($found === null) {
            return false;
        }

        $feet = fn(array $box) => [$box['x'] + $box['width'] / 2, $box['y'] + $box['height']];
        [$fx, $fy] = $feet($found);
        [$ex, $ey] = $feet($this->box);
        $ratio = $found['height'] / max(0.001, $this->box['height']);

        return hypot($fx - $ex, $fy - $ey) <= (float) Config::get('pipeline.keyframes.move.tolerance') && $ratio > 0.75 && $ratio < 1.33;
    }
}
