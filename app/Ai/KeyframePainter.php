<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Keyframe;
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
     * @param  array<int, StoredImage>  $attachments
     *
     * @throws Throwable when the image model fails; the failure is logged on the keyframe first.
     */
    public function paint(Keyframe $keyframe, string $prompt, array $attachments = []): Media
    {
        $shot = $keyframe->shot;
        $model = Config::get('pipeline.models.image');
        $started = hrtime(true);

        try {
            $response = Image::of($prompt)
                ->size($shot->aspectRatio()->value)
                ->attachments($attachments)
                ->timeout(180)
                ->generate('openrouter', $model);
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
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $image = $response->firstImage();

        $extension = match ($image->mime()) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $render = $keyframe
            ->addMediaFromString($image->content())
            ->usingFileName("keyframe-{$keyframe->position}.{$extension}")
            ->toMediaCollection(Keyframe::RENDERS);

        $keyframe->forceFill(['render_id' => $render->id, 'rendering' => false, 'render_error' => null])->save();

        return $render;
    }

    /**
     * A stored render as a reference image for the model.
     */
    public function referenceFor(Media $render): StoredImage
    {
        return ImageFile::fromStorage($render->getPathRelativeToRoot(), $render->disk);
    }
}
