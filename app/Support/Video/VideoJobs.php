<?php

declare(strict_types=1);

namespace App\Support\Video;

/**
 * A provider that renders clips: the status of its jobs and the finished
 * clip. Luma renders scenes, close-ups and the stills of a montage;
 * OpenRouter the presenter's lip-synced clips.
 *
 * @phpstan-type VideoStatus array{id: string, status: string, error?: string|null, usage?: array<string, mixed>|null}
 */
interface VideoJobs
{
    /**
     * @param  list<string>  $ids
     * @return array<string, VideoStatus>
     */
    public function statuses(array $ids): array;

    public function download(string $id): string;
}
