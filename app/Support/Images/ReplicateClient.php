<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\TransientHttpFailure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Runs a model on Replicate and downloads what it made: the request waits
 * for the prediction where Replicate allows it, and polls after that.
 */
class ReplicateClient
{
    /** How long to keep polling a prediction, in seconds. */
    private const int WAIT_SECONDS = 120;

    public static function configured(): bool
    {
        return filled(Config::get('services.replicate.token'));
    }

    /**
     * Run one prediction and return its output: a URL, or a list of them.
     *
     * @param  array<string, mixed>  $input
     * @param  int  $waitSeconds  how long to keep polling, longer for a model that works through a video
     */
    public function run(string $version, array $input, int $waitSeconds = self::WAIT_SECONDS): mixed
    {
        $prediction = $this->request()
            ->withHeaders(['Prefer' => 'wait=60'])
            ->post('predictions', ['version' => $version, 'input' => $input])
            ->throw()
            ->json();

        $deadline = time() + $waitSeconds;

        while (in_array($prediction['status'] ?? null, ['starting', 'processing'], true) && time() < $deadline) {
            Sleep::for(1500)->milliseconds();
            $prediction = $this->request()->get((string) $prediction['urls']['get'])->throw()->json();
        }

        if (($prediction['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('The Replicate model did not finish: ' . ($prediction['error'] ?? $prediction['status'] ?? 'no answer'));
        }

        return $prediction['output'];
    }

    /**
     * Uploads a file too large for a data URL, such as a video, and returns
     * its id and the URL a model takes as input.
     *
     * @return array{id: string, url: string}
     */
    public function upload(string $bytes, string $fileName, string $mime): array
    {
        $file = $this->request()
            ->timeout(180)
            ->attach('content', $bytes, $fileName, ['Content-Type' => $mime])
            ->post('files')
            ->throw()
            ->json();

        return ['id' => (string) $file['id'], 'url' => (string) $file['urls']['get']];
    }

    /**
     * Removes an uploaded file once the model is done with it.
     */
    public function forget(string $fileId): void
    {
        $this->request()->delete("files/{$fileId}");
    }

    /**
     * The bytes behind an output URL.
     */
    public function download(string $url): string
    {
        return Http::timeout(60)
            ->retry(TransientHttpFailure::ATTEMPTS, TransientHttpFailure::WAIT_MS, fn(Throwable $exception) => TransientHttpFailure::retryable($exception))
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * An image as the data URL Replicate takes as input.
     */
    public static function dataUrl(string $image): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image) ?: 'image/png';

        return "data:{$mime};base64," . base64_encode($image);
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl((string) Config::get('services.replicate.url'))
            ->withToken((string) Config::get('services.replicate.token'))
            ->acceptJson()
            ->timeout(90)
            ->retry(TransientHttpFailure::ATTEMPTS, TransientHttpFailure::WAIT_MS, fn(Throwable $exception) => TransientHttpFailure::retryable($exception));
    }
}
