<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\StorylineWriter;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\Shot;
use Illuminate\Support\Collection;

/**
 * Planning the keyframes of a shot, once for every storyline suggested for
 * it, as if the director had chosen that one.
 */
class KeyframePlanBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'keyframe-plan';
    }

    public function description(): string
    {
        return 'Break a chosen storyline into keyframes with image prompts.';
    }

    public function cases(): Collection
    {
        return Shot::query()->with('project')->whereNotNull('storyline_options')->orderBy('id')->get()
            ->flatMap(fn(Shot $shot) => collect($shot->storylineOptions())->map(fn(array $option, int $index) => new BenchCase(
                key: "shot-{$shot->getKey()}-option-" . ($index + 1),
                label: "{$shot->project->title} · {$shot->title} · {$option['title']}",
                data: ['shot' => $shot->getKey(), 'option' => $index],
            )))
            ->values();
    }

    /**
     * With the storyline of this case chosen and no plan yet, as right after
     * the director picks it.
     */
    public function agent(BenchCase $case): StorylineWriter
    {
        $shot = Shot::query()->with('project')->findOrFail($case->data['shot']);
        $shot->chosen_storyline = $shot->storylineOptions()[$case->data['option']];
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
            'storyline' => 'The keyframes tell the chosen storyline from start to end and land the takeaway, with the fewest keyframes that do so.',
            'readable' => 'Every keyframe is one clearly readable state (pose, position, object state), not motion.',
            'consistent' => 'The subject, setting, backdrop and objects are described with the same wording in every image prompt.',
            'staging' => 'Every prompt follows the staging rules: calm backdrop directly behind the character, nothing crossing the figure, at most two context objects, plain ground.',
            'rules' => 'Prompts are 50 to 90 words, present tense, no style words, the elements list only names cast and sets that are visible, no camera language, no text, English.',
        ];
    }
}
