<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\StyleOptionStatus;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Renders one style sheet: the project's subjects, from the attached content
 * photos, in the option's style.
 */
#[DeleteWhenMissingModels]
class GenerateStyleOption implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly StyleOption $option,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $option = $this->option->load('project');
        $project = $option->project;
        $model = Config::get('pipeline.models.image');
        $started = hrtime(true);

        $attachments = $project->styleSheetSubjects()
            ->map(fn(Media $media) => ImageFile::fromStorage(
                $media->getPathRelativeToRoot(Project::REFERENCE),
                $media->conversions_disk ?? $media->disk,
            ))
            ->all();

        $response = Image::of($option->prompt)
            ->attachments($attachments)
            ->square()
            ->timeout($this->timeout)
            ->generate('openrouter', $model);

        $image = $response->firstImage();

        $option->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'image',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $option
            ->addMediaFromString($image->content())
            ->usingFileName('style-sheet-' . $option->sqid . match ($image->mime()) {
                'image/jpeg' => '.jpg',
                'image/webp' => '.webp',
                default => '.png',
            })
            ->toMediaCollection(StyleOption::RENDER);

        $option->forceFill([
            'status' => StyleOptionStatus::READY,
            'error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->option->generations()->create([
            'director_id' => $this->option->project->director_id,
            'kind' => 'image',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.image'),
            'error' => $exception?->getMessage(),
        ]);

        $this->option->forceFill([
            'status' => StyleOptionStatus::FAILED,
            'error' => __('This style could not be rendered.'),
        ])->save();
    }
}
