<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Bench\AgentBenchmark;
use App\Ai\Bench\BenchCase;
use App\Ai\KeyframePainter;
use App\Models\Keyframe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\StoredImage;

/**
 * Checking a drawn keyframe against its references, on the chosen render of
 * every keyframe that has one: a pose that is not natural is reported, and
 * nothing a viewer would not see as a mistake.
 */
class KeyframeCheckBenchmark extends AgentBenchmark
{
    public function __construct(private readonly KeyframePainter $painter) {}

    public function name(): string
    {
        return 'keyframe-check';
    }

    public function description(): string
    {
        return 'Check a drawn keyframe: unnatural poses and what a viewer would see change.';
    }

    public function cases(): Collection
    {
        return Keyframe::query()->with('shot.project')->whereNotNull('render_id')->orderBy('id')->get()
            ->filter(fn(Keyframe $keyframe) => $this->input($keyframe) !== null)
            ->map(fn(Keyframe $keyframe) => new BenchCase(
                key: "keyframe-{$keyframe->getKey()}",
                label: "{$keyframe->shot->project->title} · {$keyframe->shot->code()} keyframe {$keyframe->position}",
                data: ['keyframe' => $keyframe->getKey()],
            ))
            ->values();
    }

    public function agent(BenchCase $case): KeyframeChecker
    {
        $keyframe = $this->keyframe($case);

        return new KeyframeChecker($keyframe, $this->input($keyframe)[1] ?? []);
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptFor();
    }

    public function attachments(BenchCase $case): array
    {
        return $this->input($this->keyframe($case))[0] ?? [];
    }

    public function model(): string
    {
        return (string) Config::get('pipeline.models.keyframe_check');
    }

    public function expectedOutputTokens(): int
    {
        return 400;
    }

    public function criteria(): array
    {
        return [
            'natural_pose' => 'Every person in the keyframe to check whose pose a real person could not take naturally, such as a head turned further than the neck allows or a wrist bent the wrong way, is a high issue; no natural pose is reported.',
            'no_false_alarms' => 'Every high issue is a mistake a viewer would see: it makes the story wrong or changes at a glance from the keyframe before. Nothing the references allow is reported.',
        ];
    }

    private function keyframe(BenchCase $case): Keyframe
    {
        return Keyframe::query()->with('shot.project')->findOrFail($case->data['keyframe']);
    }

    /**
     * @return array{0: list<StoredImage>, 1: list<string>}|null
     */
    private function input(Keyframe $keyframe): ?array
    {
        $shot = $keyframe->shot;
        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $keyframe = $siblings->firstWhere('id', $keyframe->id);
        $render = $keyframe?->render();

        return $render === null ? null : $this->painter->checkInput($keyframe, $render, $this->painter->referencesFor($keyframe, $siblings));
    }
}
