<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Element;
use App\Models\Shot;
use App\Support\Shots\SequenceDirecting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Directs the story of a plan chat turn into a sequence of shots in the
 * background, as directing, reviewing and repairing it takes longer than a
 * web request may wait. The turn shows that the shots are being laid out
 * until the sequence, or why there is none, is on it.
 */
#[DeleteWhenMissingModels]
class DirectSequence implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $leftOut  names of the cast and sets the director left out
     */
    public function __construct(
        public readonly Shot $shot,
        public readonly int $turn,
        public readonly array $leftOut = [],
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');
        $project = $shot->project;
        $story = (string) (($shot->plan_chat ?? [])[$this->turn]['story'] ?? '');
        [$direction, $undirected, $missing] = SequenceDirecting::direct($project, $shot, $story, $this->leftOut);

        if ($direction === null) {
            $this->finish($undirected ?? __('(I could not lay out the shots right now. Ask me again.)'));

            return;
        }

        $names = array_map(fn(string $name) => mb_strtolower($name), $direction->elementNames());
        $cast = $project->elements()->get()->filter(fn(Element $element) => in_array(mb_strtolower($element->name), $names, true))->pluck('sqid')->values()->all();

        $this->update(fn(array $turn) => [
            ...array_diff_key($turn, ['directing' => true]),
            'sequence' => $direction->toArray(),
            'cast' => $cast,
            ...($this->leftOut === [] ? [] : ['left_out' => $this->leftOut]),
        ]);

        // What the sequence needs that the cast and sets do not have is offered to make.
        if ($missing !== []) {
            $new = GenerateStoryline::newElementsFrom($shot, [...(array) ($shot->fresh()->storyline['new_elements'] ?? []), ...$missing]);
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'new_elements' => $new]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish(__('(I could not lay out the shots right now. Ask me again.)'));
    }

    /**
     * Ends the laying out without a new sequence: a sequence the turn had stays, and the reason is told.
     */
    private function finish(string $why): void
    {
        $this->update(fn(array $turn) => [
            ...array_diff_key($turn, ['directing' => true]),
            'text' => trim(((string) ($turn['text'] ?? '')) . ' ' . $why),
        ]);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function update(callable $change): void
    {
        $this->shot->updateStoredJson('plan_chat', function (?array $turns) use ($change) {
            $turns = array_values((array) $turns);

            if (isset($turns[$this->turn])) {
                $turns[$this->turn] = $change($turns[$this->turn]);
            }

            return $turns;
        });
    }
}
