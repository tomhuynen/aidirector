<?php

declare(strict_types=1);

namespace App\Support\PhotoSearch;

use App\Models\PhotoSuggestion;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Turns the director's photo searches into one gallery. Each query looks on
 * the client's website first and tops up from the whole web; small images
 * and duplicates are dropped.
 */
class SuggestPhotos
{
    public function __construct(
        private readonly SerperImageSearch $search,
    ) {}

    /**
     * @param  list<string>  $queries
     * @return Collection<int, PhotoSuggestion>
     */
    public function suggest(Project $project, array $queries): Collection
    {
        $queries = array_slice(array_values(array_unique(array_filter(array_map('trim', $queries)))), 0, (int) Config::get('pipeline.photo_search.max_queries'));

        if ($queries === []) {
            return collect();
        }

        $domain = $this->domain($project->website);
        $requests = [];

        foreach ($queries as $query) {
            if ($domain !== null) {
                $requests[] = ['query' => $query, 'q' => "site:{$domain} {$query}", 'website' => true];
            }

            $requests[] = ['query' => $query, 'q' => $query, 'website' => false];
        }

        $started = hrtime(true);
        $results = $this->search->searchMany(array_column($requests, 'q'));

        $project->generations()->create([
            'director_id' => $project->director_id,
            'kind' => 'search',
            'provider' => 'serper',
            'model' => 'google-images',
            'prompt' => implode("\n", array_column($requests, 'q')),
            'cost' => count($requests) * (float) Config::get('pipeline.photo_search.cost_per_request'),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        $perQuery = (int) Config::get('pipeline.photo_search.per_query');
        $minEdge = (int) Config::get('pipeline.photo_search.min_edge');
        $seen = $project->photoSuggestions()->pluck('image_url')->flip()->all();
        $picked = [];

        foreach ($queries as $query) {
            $count = 0;

            foreach ($requests as $index => $request) {
                if ($request['query'] !== $query) {
                    continue;
                }

                foreach ($results[$index] ?? [] as $result) {
                    if ($count >= $perQuery) {
                        break 2;
                    }

                    if (isset($seen[$result->imageUrl]) || ! $this->largeEnough($result, $minEdge)) {
                        continue;
                    }

                    // Google does not always honour site:, so off-site hits from the website search are skipped here.
                    if ($request['website'] && ! $this->isOnWebsite($result, (string) $domain)) {
                        continue;
                    }

                    $seen[$result->imageUrl] = true;
                    $picked[] = [$query, $result, $request['website']];
                    $count++;
                }
            }
        }

        $batch = (int) $project->photoSuggestions()->max('batch') + 1;

        return collect($picked)->values()->map(fn(array $entry, int $index) => $project->photoSuggestions()->create([
            'batch' => $batch,
            'position' => $index + 1,
            'query' => $entry[0],
            'image_url' => $entry[1]->imageUrl,
            'thumbnail_url' => $entry[1]->thumbnailUrl,
            'source_url' => $entry[1]->sourceUrl,
            'title' => $entry[1]->title === null ? null : mb_substr($entry[1]->title, 0, 255),
            'domain' => $entry[1]->domain,
            'width' => $entry[1]->width,
            'height' => $entry[1]->height,
            'from_website' => $entry[2],
        ]));
    }

    /**
     * The bare host of the client's website, for a site: search.
     */
    private function domain(?string $website): ?string
    {
        if (blank($website)) {
            return null;
        }

        $host = parse_url(str_contains($website, '://') ? $website : "https://{$website}", PHP_URL_HOST);

        return is_string($host) && $host !== '' ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    private function isOnWebsite(ImageResult $result, string $domain): bool
    {
        $host = strtolower((string) ($result->domain ?: parse_url((string) $result->sourceUrl, PHP_URL_HOST)));

        return $domain !== '' && ($host === $domain || str_ends_with($host, ".{$domain}"));
    }

    private function largeEnough(ImageResult $result, int $minEdge): bool
    {
        if ($result->width === null || $result->height === null) {
            return true;
        }

        return max($result->width, $result->height) >= $minEdge;
    }
}
