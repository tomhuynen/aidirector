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
    private const int WAIT_SECONDS = 240;

    public static function configured(): bool
    {
        return filled(Config::get('services.replicate.token'));
    }

    /**
     * Run one prediction and return its output: a URL, or a list of them.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $version, array $input): mixed
    {
        return $this->finish($this->request()
            ->withHeaders(['Prefer' => 'wait=60'])
            ->post('predictions', ['version' => $version, 'input' => $input])
            ->throw()
            ->json());
    }

    /**
     * Polls a prediction until it is done and returns its output.
     *
     * @param  array<string, mixed>  $prediction
     */
    private function finish(array $prediction): mixed
    {
        $deadline = time() + self::WAIT_SECONDS;

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
     * Run one prediction of an official model, named by its owner and name
     * such as `black-forest-labs/flux-3-image`, and return its output.
     *
     * @param  array<string, mixed>  $input
     */
    public function runModel(string $model, array $input): mixed
    {
        return $this->finish($this->request()
            ->withHeaders(['Prefer' => 'wait=60'])
            ->post("models/{$model}/predictions", ['input' => $input])
            ->throw()
            ->json());
    }

    /**
     * Starts one prediction of an official model without waiting for it, and returns its id.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(string $model, array $input): string
    {
        $id = $this->request()->post("models/{$model}/predictions", ['input' => $input])->throw()->json('id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Replicate did not start the prediction.');
        }

        return $id;
    }

    /**
     * The state of a prediction: its status, its output once it succeeded and its error once it failed.
     *
     * @return array{status: string, output: mixed, error: string|null}
     */
    public function prediction(string $id): array
    {
        $prediction = $this->request()->get("predictions/{$id}")->throw()->json();

        return [
            'status' => (string) ($prediction['status'] ?? ''),
            'output' => $prediction['output'] ?? null,
            'error' => isset($prediction['error']) ? (string) $prediction['error'] : null,
        ];
    }

    /**
     * Uploads a file for a model's input, and returns the URL the input takes.
     */
    public function upload(string $bytes, string $name, string $mime): string
    {
        $url = $this->request()->attach('content', $bytes, $name, ['Content-Type' => $mime])->post('files')->throw()->json('urls.get');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Replicate did not take the file.');
        }

        return $url;
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
