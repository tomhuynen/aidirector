<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ElementPainter;
use App\Enums\ElementType;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Element;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Draws or changes an element's reference image from its page: with an
 * instruction the current image is edited, without one it is drawn from the
 * description.
 */
#[DeleteWhenMissingModels]
class UpdateElementImage implements ShouldQueue
{
    use MarksRenderFailures;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Element $element,
        public readonly ?string $instruction = null,
        /** @var list<int> ids of the project's elements to draw into this one */
        public readonly array $includes = [],
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ElementPainter $painter): void
    {
        $element = $this->element->load('project');

        $includes = $this->includes === [] ? [] : $element->project->elements()->whereKey($this->includes)->with('media')->get()->all();

        filled($this->instruction) && $element->reference() !== null
            ? $painter->edit($element, (string) $this->instruction, $includes)
            : $painter->paint($element, includes: $includes);

        $element->forceFill(['rendering' => false, 'render_error' => null])->save();

        // A person's voice follows from how they look; one chosen before stays.
        if ($element->type === ElementType::PERSON && $element->settings->voice === null) {
            JudgeElementVoice::dispatch($element);
        }

        GenerationFinished::ready(
            __('The image of “:name” is ready', ['name' => $element->name]),
            route('public.projects.elements.view', [$element->project, $element]),
            $element->reference(),
            Element::THUMBNAIL,
        )->sendTo($element->project);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markRenderFailed($this->element, __('The image could not be generated. Please try again.'), $exception);

        $project = $this->element->project()->first();

        if ($project !== null) {
            GenerationFinished::failed(__('The image of “:name” could not be drawn', ['name' => $this->element->name]), route('public.projects.elements.view', [$project, $this->element]))
                ->sendTo($project);
        }
    }
}
