<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\VoiceJudge;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Models\Element;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Decides from a person's picture whether they speak with a male or a female
 * voice as a presenter, and keeps it in their settings. A voice the director
 * or an earlier judgement chose stays.
 */
#[DeleteWhenMissingModels]
class JudgeElementVoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Element $element,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        self::judge($this->element);
    }

    /**
     * The person's voice, judged from their picture the first time and kept.
     * Null for an element that is no person or has no picture yet, or when
     * the judgement fails.
     *
     * @return 'male'|'female'|null
     */
    public static function judge(Element $element): ?string
    {
        if ($element->settings->voice !== null || $element->type !== ElementType::PERSON) {
            return $element->settings->voice;
        }

        $picture = $element->reference();

        if ($picture === null) {
            return null;
        }

        $judge = new VoiceJudge();
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $judge->prompt($judge->promptFor(), attachments: [app(KeyframePainter::class)->referenceFor($picture)], provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $voice = ($response->toArray()['voice'] ?? null) === 'female' ? 'female' : 'male';
        $element->forceFill(['settings' => $element->settings->withVoice($voice)])->save();

        return $voice;
    }
}
