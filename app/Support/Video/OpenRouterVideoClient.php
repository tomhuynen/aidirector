<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * The OpenRouter video API, which the AI SDK does not cover: submit a job,
 * poll its status and download the finished clip.
 *
 * @phpstan-type VideoStatus array{id: string, status: string, error?: string|null, usage?: array<string, mixed>|null, unsigned_urls?: list<string>}
 */
class OpenRouterVideoClient
{
    /**
     * Submit a generation and return the provider's job id.
     *
     * @param  list<string>  $references  Reference images as data URLs.
     */
    public function submit(string $model, string $prompt, array $references, int $duration, string $aspectRatio, string $resolution): string
    {
        $response = $this->client()->post('videos', array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'duration' => $duration,
            'aspect_ratio' => $aspectRatio,
            'resolution' => $resolution,
            'generate_audio' => false,
            'input_references' => array_map(fn(string $url) => [
                'type' => 'image_url',
                'image_url' => ['url' => $url],
            ], $references) ?: null,
        ], fn(mixed $value) => $value !== null))->throw();

        return (string) $response->json('id');
    }

    /**
     * @return VideoStatus
     */
    public function status(string $id): array
    {
        /** @var VideoStatus */
        return $this->client()->get("videos/{$id}")->throw()->json();
    }

    /**
     * The bytes of the finished clip.
     */
    public function download(string $id): string
    {
        return $this->client()->timeout(300)->get("videos/{$id}/content", ['index' => 0])->throw()->body();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(Config::get('pipeline.video.url'))
            ->withToken((string) Config::get('ai.providers.openrouter.key'))
            ->acceptJson()
            ->timeout(60);
    }
}
