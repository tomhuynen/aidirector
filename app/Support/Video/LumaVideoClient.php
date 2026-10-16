<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Luma Agents API for Ray: submit a clip with every keyframe pinned to
 * the frame it must be reached on, poll its state and download the result.
 *
 * @phpstan-import-type VideoStatus from VideoJobs
 */
class LumaVideoClient implements VideoJobs
{
    /** Ray renders 24 frames a second; a keyframe is pinned to a frame on that grid. */
    public const FPS = 24;

    /** The clip lengths Ray renders, in seconds. */
    public const LENGTHS = [5, 10];

    /**
     * Submit a clip and return the generation id.
     *
     * @param  list<array{bytes: string, mime: string}>  $keyframes  the images, in the order of their frames
     * @param  list<int>  $frames  the frame each image is pinned to, rising
     */
    public function submit(string $model, string $prompt, array $keyframes, array $frames, int $seconds, string $aspectRatio, string $resolution): string
    {
        $response = $this->client()->post('generations', [
            'model' => $model,
            'type' => 'video',
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
            'video' => [
                'resolution' => self::resolution($resolution),
                'duration' => "{$seconds}s",
                'keyframes' => array_map(fn(array $image) => ['data' => base64_encode($image['bytes']), 'media_type' => $image['mime']], $keyframes),
                'keyframe_indexes' => $frames,
            ],
        ])->throw();

        return (string) $response->json('id');
    }

    /**
     * The state of several generations at once, asked in parallel, in the
     * shape the poller reads. A generation whose state could not be read is left out.
     *
     * @param  list<string>  $ids
     * @return array<string, VideoStatus>
     */
    public function statuses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $responses = Http::pool(fn(Pool $pool) => array_map(
            fn(string $id) => $pool->as($id)
                ->baseUrl((string) Config::get('services.luma.url'))
                ->withToken((string) Config::get('services.luma.key'))
                ->acceptJson()
                ->timeout(60)
                ->get("generations/{$id}"),
            $ids,
        ), concurrency: 10);

        $statuses = [];

        foreach ($responses as $id => $response) {
            if ($response instanceof Response && $response->successful() && is_string($response->json('state'))) {
                $statuses[(string) $id] = [
                    'id' => (string) $id,
                    'status' => (string) $response->json('state'),
                    'error' => $response->json('failure_reason'),
                ];
            }
        }

        return $statuses;
    }

    /**
     * The bytes of the finished clip, from the link the generation has now; links expire after an hour.
     */
    public function download(string $id): string
    {
        $url = $this->client()->get("generations/{$id}")->throw()->json('output.0.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('The finished video has no link to download it from.');
        }

        return Http::timeout(300)->get($url)->throw()->body();
    }

    /**
     * The shortest clip Ray renders that holds the given seconds.
     */
    public static function lengthFor(int $seconds): int
    {
        foreach (self::LENGTHS as $length) {
            if ($seconds <= $length) {
                return $length;
            }
        }

        return max(self::LENGTHS);
    }

    /**
     * What a clip costs in USD, from the price list in the config; null for a resolution without a price.
     */
    public static function cost(int $seconds, string $resolution): ?float
    {
        $price = Config::get('pipeline.video.luma_prices.' . self::resolution($resolution) . ".{$seconds}");

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Ray has no 480p; the nearest it renders is 540p.
     */
    public static function resolution(string $resolution): string
    {
        return $resolution === '480p' ? '540p' : $resolution;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) Config::get('services.luma.url'))
            ->withToken((string) Config::get('services.luma.key'))
            ->acceptJson()
            ->timeout(60);
    }
}
