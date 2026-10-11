<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\LeftBehindReader;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Once the keyframes of a shot in a place of its sequence are drawn, reads
 * from its last keyframe what it leaves behind there, such as a landed
 * helicopter, for the later shots in that place. Those wait for it; when it
 * changes after they were drawn, they say so in their chat.
 */
#[DeleteWhenMissingModels]
class ReadLeftBehind implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly Shot $shot)
    {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function uniqueId(): string
    {
        return 'left-behind-' . (Tenant::current()?->getKey() ?? 'landlord') . '-' . $this->shot->getKey();
    }

    public function handle(SequencePlaces $places): void
    {
        $shot = $this->shot->load('project');
        $place = $places->of($shot);
        $last = $shot->keyframes()->with(['media', 'elements'])->orderByDesc('position')->first();
        $render = $last?->render();

        if ($place === null || $render === null || ($shot->storyline[SequencePlaces::LEFT_BEHIND]['for'] ?? null) === $render->id) {
            return;
        }

        $things = $shot->keyframes()->with('elements')->get()->flatMap->elements->filter(fn(Element $element) => $element->type === ElementType::OBJECT)->pluck('name')->unique()->values()->all();
        $reader = new LeftBehindReader($place['name'], $place['map'], $things);

        /** @var StructuredAgentResponse $response */
        $response = $reader->prompt($reader->promptFor(), attachments: [ImageFile::fromStorage($render->getPathRelativeToRoot(), $render->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        $answer = $response->toArray();
        $left = trim((string) ($answer['left'] ?? ''));
        $before = $shot->storyline[SequencePlaces::LEFT_BEHIND]['text'] ?? null;

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), SequencePlaces::LEFT_BEHIND => [
            'for' => $render->id,
            'text' => $left,
            'things' => array_values(array_filter(array_map(fn(mixed $name) => trim((string) $name), (array) ($answer['things'] ?? [])))),
        ]]);

        // Read before and different now: later shots in this place already drawn may no longer match.
        if ($before !== null && $before !== $left) {
            foreach ($places->dependents($shot->fresh()) as $later) {
                if ($later->keyframes()->whereNotNull('render_id')->exists()) {
                    $later->say(__(':shot ends differently now: :left This shot may no longer match it; ask me to draw it again if it does not.', ['shot' => $shot->code(), 'left' => $left === '' ? __('nothing is left behind there.') : $left]));
                }
            }
        }

        Shot::drawWaitingShots($shot->project_id);
    }
}
