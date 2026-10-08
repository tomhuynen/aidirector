<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\StorylineWriter;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Drafts the plan of a shot from its takeaway, such as for the shots the
 * project setup makes: the storyline and the keyframes. The director goes on
 * from the draft in the plan chat; nothing is drawn yet.
 */
#[DeleteWhenMissingModels]
class GenerateStoryline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        /** Keep the kind the shot already has, such as the one the project setup chose; otherwise the planner chooses. */
        public readonly bool $keepKind = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');

        if (! $this->keepKind) {
            $shot->kind = null;
        }

        $writer = new StorylineWriter($shot);
        $started = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt(
            $writer->promptFor(),
            provider: 'openrouter',
            model: Config::get('pipeline.models.text'),
        );

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? Config::get('pipeline.models.text'),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $shot->forgetKeyframes();

        $title = trim((string) ($response['title'] ?? ''));

        $shot->forceFill([
            // A shot still named after its takeaway gets the planner's title; a title someone gave it stays.
            'title' => $title !== '' && (blank($shot->title) || $shot->title === Str::limit((string) $shot->takeaway, 80)) ? $title : $shot->title,
            'chosen_storyline' => [
                'title' => $title !== '' ? $title : (string) $shot->title,
                'storyline' => trim((string) ($response['storyline'] ?? '')),
            ],
            'kind' => $shot->kind ?? ShotKind::tryFrom((string) ($response['kind'] ?? '')) ?? ShotKind::SCENE,
            'storyline' => [
                // Places and objects the plan needs that the cast and sets do not have yet, for the director to add.
                ...(($new = self::newElementsFrom($shot, $response['new_elements'] ?? null)) !== [] ? ['new_elements' => $new] : []),
                'framing' => $response['framing'],
                'keyframes' => array_values($response['keyframes']),
            ],
            'storyline_error' => null,
            // The plan chat starts from the draft.
            'plan_chat' => [[
                'role' => 'assistant',
                'stage' => 'plan',
                'text' => __('I drafted this plan as a :kind: :storyline What would you like to change?', [
                    'kind' => mb_strtolower(($shot->kind ?? ShotKind::tryFrom((string) ($response['kind'] ?? '')) ?? ShotKind::SCENE)->label()),
                    'storyline' => trim((string) ($response['storyline'] ?? '')),
                ]),
            ]],
            'voice_over' => null,
            'status' => ShotStatus::STORYLINE_READY,
        ])->save();

        GenerateVoiceOver::dispatch($shot);
    }

    /**
     * The places and objects the planner proposes to add to the cast and
     * sets, without the ones the project already has under that name.
     *
     * @return list<array{name: string, type: string, description: string}>
     */
    public static function newElementsFrom(Shot $shot, mixed $proposed): array
    {
        $existing = $shot->project->elements()->pluck('name')->map(fn(string $name) => mb_strtolower(trim($name)))->all();

        return collect(is_array($proposed) ? $proposed : [])
            ->filter(fn(mixed $element) => is_array($element) && trim((string) ($element['name'] ?? '')) !== '' && trim((string) ($element['description'] ?? '')) !== '')
            ->map(fn(array $element) => [
                'name' => trim((string) $element['name']),
                'type' => in_array($element['type'] ?? '', ['person', 'place'], true) ? $element['type'] : 'object',
                'description' => trim((string) $element['description']),
            ])
            ->reject(fn(array $element) => in_array(mb_strtolower($element['name']), $existing, true))
            ->unique(fn(array $element) => mb_strtolower($element['name']))
            ->values()
            ->all();
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

        $this->shot->forceFill([
            'storyline_error' => __('The keyframes could not be planned. Please try again.'),
            'status' => $this->shot->storyline === null ? ShotStatus::DRAFT : ShotStatus::STORYLINE_READY,
        ])->save();
    }
}
