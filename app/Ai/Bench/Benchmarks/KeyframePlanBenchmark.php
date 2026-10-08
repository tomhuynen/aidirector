<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\StorylineWriter;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\Shot;
use Illuminate\Support\Collection;

/**
 * Planning a shot from its takeaway: the storyline, the keyframes and their
 * descriptions, as the planner drafts it for a shot made in the project setup.
 */
class KeyframePlanBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'keyframe-plan';
    }

    public function description(): string
    {
        return 'Plan a shot from its takeaway: storyline and keyframe descriptions.';
    }

    public function cases(): Collection
    {
        return Shot::query()->with('project')->where('takeaway', '!=', '')->orderBy('id')->get()
            ->map(fn(Shot $shot) => new BenchCase(
                key: "shot-{$shot->getKey()}",
                label: "{$shot->project->title} · {$shot->title}",
                data: ['shot' => $shot->getKey()],
            ))
            ->values();
    }

    /**
     * The shot with only its takeaway, before anything is planned.
     */
    public function agent(BenchCase $case): StorylineWriter
    {
        $shot = Shot::query()->with('project')->findOrFail($case->data['shot']);
        $shot->chosen_storyline = null;
        $shot->storyline = null;

        return new StorylineWriter($shot);
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptFor();
    }

    public function expectedOutputTokens(): int
    {
        return 900;
    }

    public function criteria(): array
    {
        return [
            'storyline' => 'The storyline lands the takeaway with one clear, visible action, and the keyframes tell it from start to end with the fewest keyframes that do so.',
            'readable' => 'Every keyframe is one clearly readable state (pose, position, object state), not motion.',
            'consistent' => 'The subject, the spot in the place and the objects are described with the same wording in every description.',
            'staging' => 'Every keyframe plays at one spot that belongs to the place, with no invented wall in front of it, nothing crossing a figure, at most two context objects and plain ground.',
            'rules' => 'Descriptions are 30 to 70 words, present tense, no style words, the elements list only names cast and sets that are visible, no camera language, no text, English.',
        ];
    }
}
