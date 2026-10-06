<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Concerns\ReturnsToDecisions;
use App\Http\Requests\Public\ShotPlanRequest;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanController
{
    use ReturnsToDecisions;

    /**
     * Save the plan of a shot as the director wrote or changed it, before
     * anything is drawn: the storyline, the framing and the keyframes. Each
     * image prompt goes to the image model as written. The cast and sets a keyframe
     * names are linked to it, so their pictures are attached when it is drawn.
     */
    public function store(ShotPlanRequest $request, Project $project, Shot $shot)
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        if ($shot->status !== ShotStatus::STORYLINE_READY || $shot->keyframes()->exists()) {
            throw ValidationException::withMessages(['keyframes' => __('The plan can be changed until the keyframes are drawn.')]);
        }

        $elements = $project->elements()->get();
        $current = $shot->storylineKeyframes();

        $keyframes = collect((array) $request->validated('keyframes'))->values()->map(function (array $keyframe, int $index) use ($elements, $current) {
            $description = trim((string) $keyframe['description']);
            // Ticked by the director; without a choice, the cast and sets named in the text are linked.
            $named = array_key_exists('elements', $keyframe)
                ? $elements->filter(fn(Element $element) => in_array($element->sqid, (array) $keyframe['elements'], true))->pluck('name')
                : $elements->filter(fn(Element $element) => Str::contains($keyframe['title'] . ' ' . $description, $element->name, ignoreCase: true))->pluck('name');
            $kept = ! array_key_exists('elements', $keyframe) && ($current[$index]['description'] ?? null) === $description ? (array) ($current[$index]['elements'] ?? []) : [];

            // The image prompt is sent as written; left empty, the description goes instead.
            $prompt = trim((string) ($keyframe['prompt'] ?? ''));

            return array_filter([
                'title' => trim((string) $keyframe['title']),
                'description' => $description,
                'prompt' => $prompt !== '' ? $prompt : $description,
                'must_show' => filled($keyframe['mustShow'] ?? null) ? trim((string) $keyframe['mustShow']) : null,
                'elements' => $named->merge($kept)->unique()->values()->all(),
            ], fn(mixed $value) => $value !== null);
        })->all();

        $storyline = trim((string) $request->validated('storyline'));

        $shot->forceFill([
            'rules' => $request->has('rules')
                ? array_values(array_filter(array_map(fn(mixed $rule) => trim((string) $rule), (array) $request->validated('rules')), fn(string $rule) => $rule !== ''))
                : $shot->rules,
            'chosen_storyline' => ['title' => (string) $shot->title, 'storyline' => $storyline],
            'storyline' => [
                'mode' => $shot->storyline['mode'] ?? 'auto',
                'framing' => [
                    'size' => (string) $request->validated('framing.size'),
                    'spot' => trim((string) $request->validated('framing.spot')),
                    'light' => trim((string) $request->validated('framing.light')) ?: 'as the visual style',
                    'seconds' => $request->validated('framing.seconds') !== null ? (int) $request->validated('framing.seconds') : null,
                ],
                'keyframes' => $keyframes,
            ],
        ])->save();

        return $this->afterAction($project, $shot);
    }
}
