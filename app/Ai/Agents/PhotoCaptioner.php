<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\Media;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Describes reference photos in one line each, so the rest of the pipeline
 * can reason about them as text. Runs once per batch and is not remembered:
 * a remembered conversation would resend every photo on every later turn.
 */
class PhotoCaptioner implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  Collection<int, Media>  $photos
     */
    public function __construct(
        private readonly Collection $photos,
        private readonly string $context = '',
    ) {}

    public function instructions(): Stringable|string
    {
        $count = $this->photos->count();

        return <<<INSTRUCTIONS
            You describe reference photos for an animation production team. The photos show real things that must be recognisable in the animated shots.

            Write exactly {$count} captions, one per photo, in the order the photos are given.
            A caption is one sentence of at most 25 words: what the photo shows, and the shapes, colours, markings or details an artist would need to draw it recognisably.
            Be factual. No opinions, no camera language, no names of people. Write in English.

            Project context: {$this->context}
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $count = $this->photos->count();

        return [
            'photos' => $schema->array()
                ->items($schema->object([
                    'caption' => $schema->string()->required(),
                ]))
                ->min($count)
                ->max($count)
                ->required(),
        ];
    }

    public function promptText(): string
    {
        return "Caption these {$this->photos->count()} photos.";
    }

    /**
     * The photos at reference size, in order.
     *
     * @return list<Image>
     */
    public function attachments(): array
    {
        return $this->photos
            ->map(fn(Media $media) => Image::fromStorage(
                $media->getPathRelativeToRoot(Project::REFERENCE),
                $media->conversions_disk ?? $media->disk,
            ))
            ->values()
            ->all();
    }
}
