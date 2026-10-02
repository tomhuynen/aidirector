<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Agents\PhotoAnalyst;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Models\Project;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Captioning an uploaded photo and listing the cast and sets in it, on
 * every content photo of every project.
 */
class PhotoAnalysisBenchmark extends Benchmark
{
    public function name(): string
    {
        return 'photo-analysis';
    }

    public function description(): string
    {
        return 'Caption an uploaded photo and list the people, places and objects in it.';
    }

    public function cases(): Collection
    {
        return Project::query()->orderBy('id')->get()
            ->flatMap(fn(Project $project) => $project->getMedia(Project::CONTENT_REFERENCES)->map(fn(Media $photo) => new BenchCase(
                key: "photo-{$photo->getKey()}",
                label: "{$project->title} · {$photo->file_name}",
                data: ['project' => $project->getKey(), 'media' => $photo->getKey()],
            )))
            ->values();
    }

    public function agent(BenchCase $case): PhotoAnalyst
    {
        $project = Project::query()->findOrFail($case->data['project']);
        $photo = $project->getMedia(Project::CONTENT_REFERENCES)->firstOrFail(fn(Media $media) => $media->getKey() === $case->data['media']);

        return new PhotoAnalyst($project, PhotoAnalyst::imageOf($photo));
    }

    public function prompt(BenchCase $case): string
    {
        return $this->agent($case)->promptText();
    }

    public function attachments(BenchCase $case): array
    {
        return $this->agent($case)->attachments();
    }

    public function expectedOutputTokens(): int
    {
        return 300;
    }

    public function criteria(): array
    {
        return [
            'caption' => 'The caption says accurately what the photo shows, with the shapes, colours and markings an artist needs, in at most 25 words.',
            'items' => 'The items are the most useful recurring people, places and objects in the photo, most useful first, without background clutter or anything that is not there.',
            'drawable' => 'Each description is concrete and correct enough to draw the item the same way every time.',
            'rules' => 'Types are right, names are two to four words, no names of real people, English.',
        ];
    }
}
