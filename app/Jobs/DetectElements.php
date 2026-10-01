<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\ElementDetector;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Models\Element;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * After keyframe 1 is chosen, finds the cast and sets worth keeping and puts
 * them up for the director's review. With nothing to review, the other
 * keyframes start rendering straight away; so they do when detection fails.
 */
#[DeleteWhenMissingModels]
class DetectElements implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $shot = $this->shot->load('project');
        $keyframes = $shot->keyframes()->with(['media', 'elements'])->get();
        $first = $keyframes->first()?->render() ?? throw new RuntimeException('Keyframe 1 has no chosen image.');

        $detector = new ElementDetector($shot, $keyframes, $painter->referenceFor($first));
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $detector->prompt($detector->promptText(), attachments: $detector->attachments(), provider: 'openrouter', model: $model);

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $proposals = $this->proposals((array) ($response['elements'] ?? []), $shot, $keyframes->count());

        if ($proposals === []) {
            GenerateRemainingKeyframes::startFor($shot);

            return;
        }

        $shot->forceFill([
            'element_proposals' => $proposals,
            'status' => ShotStatus::ELEMENTS_READY,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'text',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.text'),
            'error' => $exception?->getMessage(),
        ]);

        GenerateRemainingKeyframes::startFor($this->shot);
    }

    /**
     * Keeps well-formed proposals, limits keyframes to the shot's and resolves
     * a match to the existing element's id.
     *
     * @param  array<int, mixed>  $elements
     * @return list<array{name: string, type: string, description: string, keyframes: list<int>, match: string|null}>
     */
    private function proposals(array $elements, Shot $shot, int $count): array
    {
        $existing = $shot->project->elements()->get()->keyBy(fn(Element $element) => mb_strtolower(trim($element->name)));

        return collect($elements)
            ->filter(fn(mixed $element) => is_array($element) && filled($element['name'] ?? null) && ElementType::tryFrom((string) ($element['type'] ?? '')) !== null)
            ->map(function (array $element) use ($existing, $count) {
                $keyframes = collect((array) ($element['keyframes'] ?? []))
                    ->map(fn(mixed $position) => (int) $position)
                    ->filter(fn(int $position) => $position >= 1 && $position <= $count)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'name' => mb_substr(trim((string) $element['name']), 0, 120),
                    'type' => (string) $element['type'],
                    'description' => trim((string) ($element['description'] ?? '')),
                    'keyframes' => $keyframes === [] ? [1] : $keyframes,
                    'match' => $existing->get(mb_strtolower(trim((string) ($element['match'] ?? ''))))?->sqid,
                ];
            })
            ->values()
            ->all();
    }
}
