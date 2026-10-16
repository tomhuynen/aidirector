<?php

declare(strict_types=1);

namespace App\Support\Video;

/**
 * Where the clips of scenes and close-ups render: their status and the
 * finished clip, from the provider set in `pipeline.video.provider`.
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
