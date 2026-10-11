<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\TransientHttpFailure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredImage;
use RuntimeException;
use Throwable;

/**
 * The OpenRouter images endpoint, for image models that are not served
 * through chat completions and that the AI SDK therefore cannot reach.
 */
class OpenRouterImageClient
{
    /**
     * How long one attempt at an image may take, in seconds: 99 in 100 are
     * done within 100. The endpoint now and then accepts a call and never
     * answers; such a call is tried once more, and both attempts together
     * stay well under the 300 seconds of the jobs that make images.
     */
    public const int TIMEOUT = 120;

    /** How often a call that got no answer in time is tried in all. */
    public const int ATTEMPTS_AFTER_TIMEOUT = 2;

    /**
     * Whether the model has to be called through this endpoint.
     */
    public static function serves(string $model): bool
    {
        return in_array($model, (array) Config::get('pipeline.images_api_models'), true);
    }

    /**
     * Generate one image. References are sent as PNG data URLs, which every model accepts.
     *
     * @param  array<int, StoredImage>  $references
     * @return array{content: string, mime: string, cost: float|null}
     */
    public function generate(string $model, string $prompt, array $references, string $aspectRatio): array
    {
        $body = array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
            'input_references' => array_map(fn(StoredImage $image) => [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,' . base64_encode($this->png($image))],
            ], array_values($references)) ?: null,
        ]);

        $timeouts = 0;

        // Slashes stay unescaped: the endpoint misreads the mime type of an escaped data URL.
        $response = Http::baseUrl(Config::get('pipeline.video.url'))
            ->withToken((string) Config::get('ai.providers.openrouter.key'))
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            // A call that hangs is tried once more, not as often as a quick failure: more waits would outlast the job.
            ->retry(TransientHttpFailure::ATTEMPTS, TransientHttpFailure::WAIT_MS, function (Throwable $exception) use (&$timeouts) {
                if (TransientHttpFailure::timedOut($exception)) {
                    return ++$timeouts < self::ATTEMPTS_AFTER_TIMEOUT;
                }

                return TransientHttpFailure::retryable($exception);
            })
            ->withBody((string) json_encode($body, JSON_UNESCAPED_SLASHES), 'application/json')
            ->post('images')
            ->throw();

        $encoded = $response->json('data.0.b64_json');

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('The image model returned no image.');
        }

        $cost = $response->json('usage.cost');

        return [
            'content' => (string) base64_decode($encoded),
            'mime' => (string) ($response->json('data.0.media_type') ?? 'image/png'),
            'cost' => is_numeric($cost) ? (float) $cost : null,
        ];
    }

    private function png(StoredImage $image): string
    {
        $bytes = (string) Storage::disk($image->disk)->get($image->path);
        $gd = imagecreatefromstring($bytes);

        if ($gd === false) {
            return $bytes;
        }

        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
