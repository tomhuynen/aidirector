<?php

declare(strict_types=1);

namespace App\Support\PhotoSearch;

use App\Models\PhotoSuggestion;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fetches a picked photo from its source and stages it as an upload, so it
 * joins the same claim-and-caption path as a photo the director uploaded.
 */
class DownloadSuggestion
{
    private const MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @throws RuntimeException when the image cannot be fetched or is not a usable image
     */
    public function download(PhotoSuggestion $suggestion): Upload
    {
        $url = (string) $suggestion->image_url;

        $this->guardAgainstPrivateHosts($url);

        try {
            $response = Http::timeout(20)
                ->connectTimeout(5)
                ->withHeaders(['Accept' => 'image/jpeg,image/png;q=0.9,image/webp;q=0.8,*/*;q=0.1'])
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']]])
                ->get($url);
        } catch (Throwable $exception) {
            throw new RuntimeException("The photo could not be fetched: {$exception->getMessage()}", previous: $exception);
        }

        $body = $response->body();
        $max = (int) Config::get('pipeline.photo_search.max_download_bytes');

        if (! $response->successful() || $body === '' || strlen($body) > $max) {
            throw new RuntimeException('The photo could not be fetched.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $extension = self::MIME_TYPES[$mime] ?? null;

        if ($extension === null) {
            throw new RuntimeException("The photo is not a supported image ({$mime}).");
        }

        // storage/tmp is swept by app:cleanup, should the delete below ever be skipped.
        $path = storage_path('tmp/photo-' . Str::uuid());
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $body);

        $name = Str::slug(Str::limit((string) ($suggestion->title ?: $suggestion->query), 60, '')) ?: 'photo';

        try {
            return Upload::fromFile(new UploadedFile($path, "{$name}.{$extension}", $mime, test: true));
        } finally {
            File::delete($path);
        }
    }

    /**
     * Only fetch public http(s) hosts; search results should never point at
     * the server's own network, but the URL is third-party data.
     */
    private function guardAgainstPrivateHosts(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new RuntimeException('The photo address is not a public web address.');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The photo address is not a public web address.');
        }
    }
}
