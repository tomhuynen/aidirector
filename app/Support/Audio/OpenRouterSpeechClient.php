<?php

declare(strict_types=1);

namespace App\Support\Audio;

use App\Support\TransientHttpFailure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The OpenRouter speech endpoint: text in, an MP3 out.
 */
class OpenRouterSpeechClient
{
    public function speak(string $model, string $text, string $voice): string
    {
        $response = Http::baseUrl(Config::get('pipeline.video.url'))
            ->withToken((string) Config::get('ai.providers.openrouter.key'))
            ->timeout(120)
            ->retry(TransientHttpFailure::ATTEMPTS, TransientHttpFailure::WAIT_MS, fn(Throwable $exception) => TransientHttpFailure::retryable($exception))
            ->post('audio/speech', [
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
            ])
            ->throw();

        if (str_contains((string) $response->header('Content-Type'), 'json') || $response->body() === '') {
            throw new RuntimeException('The voice model returned no audio.');
        }

        return $response->body();
    }
}
