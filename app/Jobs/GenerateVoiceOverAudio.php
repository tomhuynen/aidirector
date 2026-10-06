<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\VoiceOverTranslator;
use App\Models\Shot;
use App\Support\Audio\AudioLength;
use App\Support\Audio\OpenRouterSpeechClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Makes the spoken track of one language: translates the voice-over unless
 * it is English, speaks it and makes sure it is not longer than the clip,
 * speeding it up a little or saying it shorter when needed.
 */
#[DeleteWhenMissingModels]
class GenerateVoiceOverAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Shot $shot,
        public readonly string $locale,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(OpenRouterSpeechClient $speech, AudioLength $length): void
    {
        $shot = $this->shot->load('project');
        $script = (string) $shot->voice_over;

        if (trim($script) === '') {
            throw new RuntimeException('The shot has no voice-over text yet.');
        }

        $seconds = $shot->durationInSeconds();
        $text = $this->translate($script, $seconds);
        [$audio, $spoken] = $this->speak($speech, $length, $text);

        if ($spoken > $seconds && $spoken / $seconds > (float) Config::get('pipeline.voice_over.max_speed') && ! $this->isEnglish()) {
            $text = $this->translate($text, $seconds, shorter: true);
            [$audio, $spoken] = $this->speak($speech, $length, $text);
        }

        if ($spoken > $seconds) {
            $audio = $length->speedUp($audio, min($spoken / $seconds, (float) Config::get('pipeline.voice_over.max_speed')));
        }

        $shot->getMedia(Shot::VOICE_OVERS)
            ->filter(fn($media) => $media->getCustomProperty('locale') === $this->locale)
            ->each->delete();

        $shot->addMediaFromString($audio)
            ->usingFileName("voice-over-{$shot->position}-{$this->locale}.mp3")
            ->withCustomProperties(['locale' => $this->locale, 'script' => $script, 'text' => $text])
            ->toMediaCollection(Shot::VOICE_OVERS);

        $shot->setVoiceOverTrack($this->locale, 'ready');

        if ($shot->mergedInto !== null) {
            MergeShotAudio::start($shot->mergedInto, [$this->locale]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->shot->setVoiceOverTrack($this->locale, 'failed', Str::limit((string) $exception?->getMessage(), 300));
    }

    private function isEnglish(): bool
    {
        return str_starts_with($this->locale, 'en-');
    }

    private function translate(string $text, int $seconds, bool $shorter = false): string
    {
        if ($this->isEnglish() && ! $shorter) {
            return $text;
        }

        $translator = new VoiceOverTranslator($this->locale, $seconds);
        $model = (string) Config::get('pipeline.models.text');

        /** @var StructuredAgentResponse $response */
        $response = $translator->prompt($translator->promptFor($text, $shorter), provider: 'openrouter', model: $model);

        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $translator->promptFor($text, $shorter),
            'usage' => $response->usage->toArray(),
        ]);

        $translated = trim((string) ($response->toArray()['text'] ?? ''));

        return $translated !== '' ? $translated : $text;
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function speak(OpenRouterSpeechClient $speech, AudioLength $length, string $text): array
    {
        $model = (string) Config::get('pipeline.models.voice');
        $voice = (string) Config::get("pipeline.voice_over.voices.{$this->locale}");

        if ($voice === '') {
            throw new RuntimeException("No voice is set up for {$this->locale}.");
        }

        $started = hrtime(true);

        $audio = $speech->speak($model, $text, $voice);

        $this->shot->generations()->create([
            'director_id' => $this->shot->project->director_id,
            'kind' => 'audio',
            'provider' => 'openrouter',
            'model' => $model,
            'prompt' => $text,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return [$audio, $length->seconds($audio)];
    }
}
