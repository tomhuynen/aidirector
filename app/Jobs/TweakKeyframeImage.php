<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\TweakInterpreter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Jobs\Concerns\MarksRenderFailures;
use App\Models\Keyframe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
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

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $instruction,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(KeyframePainter $painter): void
    {
        $keyframe = $this->keyframe->load('shot.project');
        $current = $keyframe->render() ?? throw new RuntimeException('The keyframe has no render to tweak.');
        $previous = $keyframe->position > 1
            ? $keyframe->shot->keyframes()->with('media')->where('position', $keyframe->position - 1)->first()?->render()
            : null;
        $references = $previous ? [$painter->referenceFor($current), $painter->referenceFor($previous)] : [$painter->referenceFor($current)];

        $instruction = $this->interpret($keyframe, $references);

        $render = $painter->paint(
            $keyframe,
            KeyframeImageBrief::tweak($instruction, withPreviousKeyframe: $previous !== null),
            $references,
            model: (string) Config::get('pipeline.models.image_edit'),
        );

        // Kept on the version, so the director can see what was asked and what the image model was told.
        $render->setCustomProperty(Keyframe::TWEAK_REQUEST, $this->instruction)
            ->setCustomProperty(Keyframe::TWEAK_INSTRUCTION, $instruction)
            ->save();
    }

    /**
     * The director's request rewritten as a precise edit instruction. If the
     * rewrite fails, the request goes to the image model as it was typed.
     *
     * @param  list<StoredImage>  $references
     */
    private function interpret(Keyframe $keyframe, array $references): string
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

            return $this->instruction;
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

        $instruction = trim((string) ($response->toArray()['instruction'] ?? ''));

        return $instruction !== '' ? $instruction : $this->instruction;
    }

    public function failed(?Throwable $exception): void
    {
        $this->markRenderFailed($this->keyframe, __('The image could not be adjusted. Please try again.'), $exception);
    }
}
