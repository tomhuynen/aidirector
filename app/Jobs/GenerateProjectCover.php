<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ProjectCoverPainter;
use App\Enums\CoverStatus;
use App\Models\Project;
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
    }

    public function failed(?Throwable $exception): void
    {
        $this->project->forceFill(['cover_status' => CoverStatus::FAILED])->save();
    }
}
