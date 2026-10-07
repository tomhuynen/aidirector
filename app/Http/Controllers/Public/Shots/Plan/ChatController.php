<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Plan;

use App\Ai\Agents\PlanChangeInterpreter;
use App\Ai\Agents\PlanFrameWriter;
use App\Enums\ShotKind;
use App\Http\Requests\Public\PlanChatRequest;
use App\Models\Element;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\StructuredAgentResponse;

class ChatController
{
    /**
     * Work out which keyframes the director's message touches, before any
     * of them is written, so the editor can reorder and show them as busy.
     */
    public function changes(PlanChatRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        // A rule removed in the editor stays removed.
        if ($request->has('rules')) {
            $shot->forceFill(['rules' => array_values(array_filter(array_map(fn(mixed $rule) => trim((string) $rule), (array) $request->validated('rules')), fn(string $rule) => $rule !== ''))]);
        }

        $interpreter = new PlanChangeInterpreter($request->keyframes(), trim((string) $request->validated('storyline')), $shot->shotRules());
        $result = $this->ask($shot, $interpreter, $interpreter->promptFor((string) $request->validated('message')));

        $changes = collect((array) ($result['changes'] ?? []))
            ->filter(fn(mixed $change) => is_array($change) && in_array($change['op'] ?? null, ['insert', 'update', 'remove', 'move'], true))
            ->map(fn(array $change) => [
                'op' => (string) $change['op'],
                'at' => max(1, (int) ($change['at'] ?? 1)),
                'from' => (int) ($change['from'] ?? 0),
                'instruction' => trim((string) ($change['instruction'] ?? '')),
            ])
            ->values()
            ->all();

        // A rule for the whole shot is kept on the shot straight away, for every drawing, adjustment and check after this.
        $rule = trim((string) ($result['rule'] ?? ''));
        $shot->addRule($rule);
        $shot->save();

        return response()->json([
            'changes' => $changes,
            'rule' => $rule,
            'rules' => $shot->shotRules(),
            'storyline' => $rule !== '' ? trim((string) ($result['storyline'] ?? '')) : '',
            'reply' => trim((string) ($result['reply'] ?? '')),
        ]);
    }

    /**
     * Write the keyframes the director asked for: in full in a drafted plan,
     * only their own words in a plan they write themselves.
     */
    public function write(PlanChatRequest $request, Project $project, Shot $shot): JsonResponse
    {
        Gate::authorize(ShotPolicy::UPDATE, $shot);

        $shot->setRelation('project', $project);
        $targets = collect((array) $request->validated('targets'))
            ->mapWithKeys(fn(array $target) => [(int) $target['position'] => (string) $target['instruction']])
            ->all();

        // Written by the rules of the kind in the plan, also when the director switched it without saving.
        if ($request->has('kind')) {
            $shot->kind = ShotKind::from((string) $request->validated('kind'));
        }

        $writer = new PlanFrameWriter($shot, ($shot->storyline['mode'] ?? 'auto') !== 'manual');
        $result = $this->ask($shot, $writer, $writer->promptFor((string) $request->validated('storyline'), $request->keyframes(), $targets));
        $elements = $project->elements()->get();

        $keyframes = collect((array) ($result['keyframes'] ?? []))
            ->filter(fn(mixed $keyframe) => is_array($keyframe) && isset($targets[(int) ($keyframe['position'] ?? 0)]))
            ->map(fn(array $keyframe) => [
                'position' => (int) $keyframe['position'],
                'title' => trim((string) ($keyframe['title'] ?? '')),
                'description' => trim((string) ($keyframe['description'] ?? '')),
                'spatial' => trim((string) ($keyframe['spatial'] ?? '')),
                'elements' => $elements
                    ->filter(fn(Element $element) => in_array($element->name, (array) ($keyframe['elements'] ?? []), true))
                    ->pluck('sqid')
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return response()->json(['keyframes' => $keyframes]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(Shot $shot, Agent $agent, string $prompt): array
    {
        $model = (string) Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($prompt, provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        return $response->toArray();
    }
}
