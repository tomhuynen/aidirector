<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\VoiceOverWriter;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Writes the voice-over once the keyframes are planned and the length is
 * known. A text that does not fit is shortened once; if it still does not
 * fit it is cut back to whole sentences that do.
 */
#[DeleteWhenMissingModels]
class GenerateVoiceOver implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** Words per second at a calm narration pace. */
    public const WORDS_PER_SECOND = 2.2;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public static function maxWords(Shot $shot): int
    {
        return max(4, (int) floor($shot->durationInSeconds() * self::WORDS_PER_SECOND));
    }

    public function handle(): void
    {
        $shot = $this->shot->load('project');
        $max = self::maxWords($shot);
        $writer = new VoiceOverWriter($shot, $max);
        $model = (string) Config::get('pipeline.models.text');

        $text = $this->write($writer, $writer->promptFor(), $model);

        if (self::words($text) > $max) {
            $text = $this->write($writer, $writer->promptFor($text), $model);
        }

        $shot->forceFill(['voice_over' => self::fit($text, $max)])->save();
    }

    private function write(VoiceOverWriter $writer, string $prompt, string $model): string
    {
        /** @var StructuredAgentResponse $response */
        $response = $writer->prompt($prompt, provider: 'openrouter', model: $model);

        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $prompt,
            'usage' => $response->usage->toArray(),
        ]);

        return trim((string) ($response->toArray()['text'] ?? ''));
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * The text within the word limit: whole sentences while they fit, otherwise the first words.
     */
    public static function fit(string $text, int $max): string
    {
        if (self::words($text) <= $max) {
            return $text;
        }

        $kept = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
            if (self::words(trim("{$kept} {$sentence}")) > $max) {
                break;
            }

            $kept = trim("{$kept} {$sentence}");
        }

        if ($kept !== '') {
            return $kept;
        }

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return rtrim(implode(' ', array_slice($words, 0, $max)), ',;:') . '.';
    }
}
