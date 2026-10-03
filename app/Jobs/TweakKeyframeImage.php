<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\TweakInterpreter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\CorrectionSource;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Keyframe;
use App\Notifications\Public\GenerationFinished;
use App\Support\Corrections\RecordCorrection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
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
 * The keyframe before it is attached as context, so a missing object can be
 * copied from where it last appeared. Tweaks use the edit model, which follows
 * pose and gaze corrections better than the model that creates keyframes.
 */
#[DeleteWhenMissingModels]
class TweakKeyframeImage implements ShouldQueue
{
    use MarksRenderFailures;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * With an option id the job adjusts that option for keyframe 1 and adds
     * the result as a new option instead of making it the chosen render.
     */
    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
        public readonly ?int $option = null,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $keyframe = $this->keyframe->load('shot.project');
        $current = ($this->option !== null ? $keyframe->renders()->firstWhere('id', $this->option) : $keyframe->render())
            ?? throw new RuntimeException('The keyframe has no render to tweak.');
        $previous = $keyframe->position > 1
            ? $keyframe->shot->keyframes()->with('media')->where('position', $keyframe->position - 1)->first()?->render()
            : null;
        $references = $previous ? [$painter->referenceFor($current), $painter->referenceFor($previous)] : [$painter->referenceFor($current)];

        ['instruction' => $instruction, 'approach' => $approach] = $this->interpret($keyframe, $references);

        $render = $approach === 'redraw'
            ? $this->redraw($painter, $keyframe, $instruction)
            : $painter->paint(
                $keyframe,
                KeyframeImageBrief::tweak($instruction, withPreviousKeyframe: $previous !== null),
                $references,
                choose: $this->option === null,
                model: (string) Config::get('pipeline.models.image_edit'),
            );

        if ($this->option !== null) {
            $keyframe->forceFill(['rendering' => false, 'render_error' => null])->save();
        }

        // Kept on the version, so the director can see what was asked and what the image model was told.
        $render->setCustomProperty(Keyframe::TWEAK_REQUEST, $this->instruction)
            ->setCustomProperty(Keyframe::TWEAK_INSTRUCTION, $instruction)
            ->save();

        RecordCorrection::record($keyframe->shot->project, CorrectionSource::ADJUSTMENT, $this->instruction, $keyframe->shot, $keyframe, RecordCorrection::keyframeContext($keyframe));

        $shot = $keyframe->shot;

        GenerationFinished::ready(
            __('Keyframe :number of “:shot” is adjusted', ['number' => $keyframe->position, 'shot' => $shot->title]),
            route('public.shots.view', [$shot->project, $shot]),
            $render,
            Keyframe::THUMBNAIL,
        )->sendTo($shot->project);
    }

    /**
     * The director's request rewritten as a precise instruction, and whether
     * an edit can make it or the keyframe has to be drawn again. If the
     * rewrite fails, the request is used as typed, as an edit.
     *
     * @param  list<StoredImage>  $references
     * @return array{instruction: string, approach: 'edit'|'redraw'}
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

            return ['instruction' => $this->instruction, 'approach' => 'edit'];
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
        ];
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
            "The director asked for this change, and it matters most: {$instruction}",
            choose: $this->option === null,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->markRenderFailed($this->keyframe, __('The image could not be adjusted. Please try again.'), $exception);

        $shot = $this->keyframe->shot()->with('project')->first();

        if ($shot !== null) {
            GenerationFinished::failed(__('Keyframe :number of “:shot” could not be adjusted', ['number' => $this->keyframe->position, 'shot' => $shot->title]), route('public.shots.view', [$shot->project, $shot]))
                ->sendTo($shot->project);
        }
    }
}
