<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ProjectCoverPainter;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;

/**
 * Draws the project's cover once setup finishes with cast and sets picked.
 * Until it exists, and if it fails, the project page shows the style sheet.
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
        if ($painter->canPaint($this->project)) {
            $painter->paint($this->project);
        }
    }
}
