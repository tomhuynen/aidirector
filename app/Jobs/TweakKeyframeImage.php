<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\TweakInterpreter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\CorrectionSource;
use App\Enums\KeyframeStatus;
use App\Jobs\Concerns\FollowsPlan;
use App\Models\Element;
use App\Models\Keyframe;
use App\Support\Corrections\RecordCorrection;
use App\Support\Shots\KeyframeChain;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Corrects the current render of a keyframe: a text model first looks at the
 * render and rewrites the director's request as a precise edit instruction,
 * then the image model gets the render itself plus that instruction, so only
 * the requested detail changes.
 * For the end frame the start frame is attached as context, so a missing object can be
 * copied from where it last appeared. Tweaks use the edit model, which follows
 * pose and gaze corrections better than the model that creates keyframes.
 */
#[DeleteWhenMissingModels]
class TweakKeyframeImage implements ShouldQueue
{
    use FollowsPlan;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * With an option id the job adjusts that option for the start frame and adds
     * the result as a new option instead of making it the chosen render.
     */
    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
        public readonly ?int $option = null,
        /** Have the text model make the request precise first; off, the request goes to the image model as typed and only the description is updated. */
        public readonly bool $rewrite = false,
        /** The director staged the keyframe anew and the request comes from it: it is kept as staged. */
        public readonly bool $describedByDirector = false,
        /** The keyframes drawn from this one are drawn again after it, so they follow the change. */
        public readonly bool $following = false,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
        $this->followPlan($this->keyframe);
    }

    public function handle(KeyframePainter $painter): void
    {
        $keyframe = $this->keyframe->load(['shot.project', 'elements']);
        // Taken before the change rewrites the description, so the correction keeps what the keyframe was meant to show.
        $intent = RecordCorrection::keyframeContext($keyframe);
        $current = ($this->option !== null ? $keyframe->renders()->firstWhere('id', $this->option) : $keyframe->render())
            ?? throw new RuntimeException('The keyframe has no render to tweak.');
        // A still of a montage has its own place, so the start frame is no reference for it.
        $previous = $keyframe->position > 1 && ! $keyframe->shot->drawsStandalone()
            ? $keyframe->shot->keyframes()->with('media')->where('position', $keyframe->position - 1)->first()?->render()
            : null;
        $references = $previous ? [$painter->referenceFor($current), $painter->referenceFor($previous)] : [$painter->referenceFor($current)];

        ['instruction' => $instruction, 'approach' => $approach, 'staging' => $staging, 'absent' => $absent] = $this->interpret($keyframe, $references);

        // Without the rewrite the request goes to the image model as typed; the text model only keeps the staging true.
        if (! $this->rewrite) {
            [$instruction, $approach] = [$this->instruction, 'edit'];
        }

        // Before drawing: a redraw is checked against the description, which must already describe the change.
        if ($this->option === null) {
            $this->describeChange($keyframe, $this->describedByDirector ? null : $staging, $absent);
        }

        $base = $painter->baseFor($keyframe, $keyframe->shot->keyframes()->with('media')->get()->each->setRelation('shot', $keyframe->shot));
        // A logo the change asks for is drawn from its picture in the branding, never from its name.
        $logos = $keyframe->shot->project->logosFor("{$this->instruction} {$instruction}");
        $labels = [...($previous !== null ? ['This version, the image that is edited', 'The start frame'] : ['This version, the image that is edited']), ...$logos->map(fn($logo) => "The {$logo->name} logo")->all()];

        $render = match (true) {
            $approach === 'redraw' => $this->redraw($painter, $keyframe, $instruction),
            default => $painter->paintOn(
                $base,
                $keyframe,
                KeyframeImageBrief::tweak($keyframe->shot->project, $instruction, withPreviousKeyframe: $previous !== null, logos: $logos->map(fn($logo) => (string) $logo->name)->all()),
                [...$references, ...$logos->map(fn($logo) => $painter->referenceFor($logo))->all()],
                choose: $this->option === null,
                model: (string) Config::get('pipeline.models.keyframe_edit'),
                labels: $labels,
            ),
        };

        // An option of the start frame still waits for the director's choice; a keyframe that changed has what is drawn from it drawn again.
        if ($this->option !== null) {
            $keyframe->forceFill(['status' => KeyframeStatus::PLANNED, 'render_error' => null])->save();
        } else {
            app(KeyframeChain::class)->changed($keyframe, $this->following);
        }

        // Kept on the version, so the director can see what was asked and what the image model was told.
        $render->setCustomProperty(Keyframe::TWEAK_REQUEST, $this->instruction)
            ->setCustomProperty(Keyframe::TWEAK_INSTRUCTION, $instruction)
            ->save();

        // A changed description is recorded as such when it is saved.
        if (! $this->describedByDirector) {
            RecordCorrection::record($keyframe->shot->project, CorrectionSource::ADJUSTMENT, $this->instruction, $keyframe->shot, $keyframe, $intent);
        }
    }

    /**
     * The director's request rewritten as a precise instruction, and whether
     * an edit can make it or the keyframe has to be drawn again. If the
     * rewrite fails, the request is used as typed, as an edit.
     *
     * @param  list<StoredImage>  $references
     * @return array{instruction: string, approach: 'edit'|'redraw', staging: KeyframeStaging|null, absent: list<string>}
     */
    private function interpret(Keyframe $keyframe, array $references): array
    {
        $interpreter = new TweakInterpreter($keyframe, $references);
        $model = (string) Config::get('pipeline.models.text');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $interpreter->prompt($interpreter->promptFor($this->instruction), attachments: $interpreter->attachments(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            $keyframe->generations()->create([
                'director_id' => $keyframe->shot->project->director_id,
                'kind' => 'text',
                'provider' => 'openrouter',
                'model' => $model,
                'prompt' => $this->instruction,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            report($exception);

            return ['instruction' => $this->instruction, 'approach' => 'edit', 'staging' => null, 'absent' => []];
        }

        $keyframe->generations()->create([
            'director_id' => $keyframe->shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $this->instruction,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        $result = $response->toArray();
        $instruction = trim((string) ($result['instruction'] ?? ''));

        return [
            'instruction' => $instruction !== '' ? $instruction : $this->instruction,
            'approach' => ($result['approach'] ?? 'edit') === 'redraw' ? 'redraw' : 'edit',
            'staging' => ($result['restage'] ?? false) === true ? KeyframeStaging::fromArray((array) ($result['staging'] ?? [])) : null,
            'absent' => array_values(array_filter(array_map('strval', (array) ($result['absent'] ?? [])))),
        ];
    }

    /**
     * Keeps the keyframe's text true to the adjusted image: a new staging
     * replaces the old one and its description, and cast or sets that left the
     * picture are taken off the keyframe, so the check, the review and later
     * redraws no longer expect them.
     *
     * @param  list<string>  $absent
     */
    private function describeChange(Keyframe $keyframe, ?KeyframeStaging $staging, array $absent): void
    {
        $shot = $keyframe->shot;
        $gone = $keyframe->elements->filter(fn(Element $element) => in_array(mb_strtolower($element->name), array_map('mb_strtolower', $absent), true));

        if ($gone->isNotEmpty()) {
            $keyframe->elements()->detach($gone->modelKeys());
            $keyframe->load('elements');
            $shot->updatePlannedKeyframe($keyframe->position, ['elements' => $keyframe->elements->pluck('name')->values()->all()]);
        }

        if ($staging !== null && $staging->summary !== '') {
            $before = $keyframe->fullDescription();
            $keyframe->restage($staging)->save();
            $shot->updatePlannedKeyframe($keyframe->position, $staging->fields(), ['copied', 'prompt']);
            // The storyline follows before the image is drawn and reviewed.
            FollowStoryline::follow($keyframe, $before);
        }
    }

    /**
     * Draws the keyframe again from its plan with the change, for changes an
     * edit cannot make, such as moving someone closer to something. Uses the
     * keyframe model and the cast and sets, like the first drawing.
     */
    private function redraw(KeyframePainter $painter, Keyframe $keyframe, string $instruction): Media
    {
        $siblings = $keyframe->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $keyframe->shot);
        $target = $siblings->firstWhere('id', $keyframe->id) ?? $keyframe;

        return $painter->render(
            $target,
            $siblings,
            "The director asked for this change, and it matters most; where it differs from the description, spot or keyframe references above, follow the change: {$instruction}",
            choose: $this->option === null,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->keyframe->newQuery()->whereKey($this->keyframe->id)->busy()->update([
            'status' => KeyframeStatus::FAILED,
            'render_error' => trim(__('The image could not be adjusted. Please try again.') . ' ' . Str::limit((string) $exception?->getMessage(), 300)),
        ]);
    }
}
