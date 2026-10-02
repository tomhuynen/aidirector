<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ProjectCoverPainter;
use App\Enums\CoverStatus;
use App\Models\Project;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws the project's cover once setup finishes with cast and sets picked.
 * The intake chat waits on its status; if it fails, the project page shows
 * the style sheet instead.
 */
#[DeleteWhenMissingModels]
class GenerateProjectCover implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Project $project,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ProjectCoverPainter $painter): void
    {
        if (! $painter->canPaint($this->project)) {
            $this->project->forceFill(['cover_status' => null])->save();

            return;
        }

        $painter->paint($this->project);
        $this->project->forceFill(['cover_status' => CoverStatus::READY])->save();

        // The old loop belongs to the old cover; a new one is made in the background.
        $this->project->clearMediaCollection(Project::COVER_LOOP);
        GenerateCoverLoop::dispatch($this->project);

        GenerationFinished::ready(
            __('The cover of “:title” is ready', ['title' => $this->project->title]),
            route('public.projects.view', $this->project),
            $this->project->getFirstMedia(Project::COVER),
        )->sendTo($this->project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->project->forceFill(['cover_status' => CoverStatus::FAILED])->save();

        GenerationFinished::failed(__('The cover of “:title” could not be drawn', ['title' => $this->project->title]), route('public.projects.view', $this->project))
            ->sendTo($this->project);
    }
}
