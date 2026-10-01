<?php

declare(strict_types=1);

namespace App\Support\PhotoSearch;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Google image results through Serper. Several queries run in parallel; a
 * failed request yields no results rather than an error, so one bad query
 * does not cost the whole gallery.
 */
class SerperImageSearch
{
    /**
     * @param  list<string>  $queries
     * @return array<int, Collection<int, ImageResult>> results per query, keyed like $queries
     */
    public function searchMany(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        $endpoint = (string) Config::get('pipeline.photo_search.endpoint');
        $limit = (int) Config::get('pipeline.photo_search.per_query');

        /** @var array<int, Response|\Throwable> $responses */
        $responses = Http::pool(fn(Pool $pool) => array_map(
            fn(string $query) => $pool
                ->withHeaders(['X-API-KEY' => (string) Config::get('pipeline.photo_search.api_key')])
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->post($endpoint, ['q' => $query, 'num' => max(10, $limit)]),
            $queries,
        ));

        return array_map(fn(mixed $response) => $this->results($response), $responses);
    }

    /**
     * @return Collection<int, ImageResult>
     */
    private function results(mixed $response): Collection
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return collect();
        }

        return collect($response->json('images', []))
            ->filter(fn(mixed $item) => is_array($item) && filled($item['imageUrl'] ?? null) && filled($item['thumbnailUrl'] ?? null))
            ->map(fn(array $item) => new ImageResult(
                imageUrl: (string) $item['imageUrl'],
                thumbnailUrl: (string) $item['thumbnailUrl'],
                sourceUrl: isset($item['link']) ? (string) $item['link'] : null,
                title: isset($item['title']) ? (string) $item['title'] : null,
                domain: isset($item['domain']) ? (string) $item['domain'] : null,
                width: isset($item['imageWidth']) ? (int) $item['imageWidth'] : null,
                height: isset($item['imageHeight']) ? (int) $item['imageHeight'] : null,
            ))
            ->values();
    }
}
