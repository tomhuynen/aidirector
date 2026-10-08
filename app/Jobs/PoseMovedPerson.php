<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\MovedPersonDescriber;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Jobs\Concerns\FollowsPlan;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\PersonCutout;
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
 * After the director moved a person in a keyframe by hand, the image model
 * redraws their pose, gaze and gesture for the new spot, on a crop around
 * them only, which is blended back in: the rest of the image cannot drift.
 * A redraw that moved the person off their spot or shifted the place is
 * drawn again. Then the description and the plan are made true to the image,
 * also when the redraw failed, and only then is the shot reviewed.
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
     */
    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
        public readonly array $box,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    public function handle(KeyframePainter $painter, PersonCutout $cutout, BackgroundDrift $drift): void
    {
        $keyframe = $this->keyframe->load(['shot.project', 'media']);
        $shot = $keyframe->shot;
        $plate = $painter->baseFor($keyframe, $shot->keyframes()->with('media')->get()->each->setRelation('shot', $shot));
        $moved = $keyframe->render();

        if ($plate === null || $plate->collection_name !== Shot::PLATE || $moved === null) {
            throw new RuntimeException('The keyframe is not drawn on a place.');
        }

        $kept = null;
        $attempts = max(1, (int) Config::get('pipeline.keyframes.move.attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $render = $this->redrawAround($painter, $cutout, $keyframe, $moved);
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
     * The pose redrawn on a crop around the person, blended back into the moved version.
     */
    private function redrawAround(KeyframePainter $painter, PersonCutout $cutout, Keyframe $keyframe, Media $moved): Media
    {
        $image = new \Imagick($moved->getPath());
        $crop = $cutout->cropAround($this->box, $image->getImageWidth(), $image->getImageHeight());
        $image->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
        $image->setImagePage(0, 0, 0, 0);
        $image->setImageFormat('png');

        $stored = 'tmp/pose/' . Str::random(16) . '.png';
        Storage::disk('local')->put($stored, $image->getImageBlob());

        try {
            $redrawn = $painter->paint(
                $keyframe,
                KeyframeImageBrief::tweak($this->instruction),
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
        $cutout->pasteBack($moved->getPath(), $redrawn->getPath(), $crop, $output);
        $sent = $redrawn->getCustomProperty(Keyframe::SENT);
        $redrawn->delete();

        return $keyframe->addMedia($output)
            ->usingFileName("keyframe-{$keyframe->position}.png")
            ->withCustomProperties([Keyframe::SENT => $sent])
            ->toMediaCollection(Keyframe::RENDERS);
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
    private function stayedPut(PersonCutout $cutout, Media $plate, Media $render): bool
    {
        $found = $cutout->locate($plate->getPath(), $render->getPath(), $this->box);

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
