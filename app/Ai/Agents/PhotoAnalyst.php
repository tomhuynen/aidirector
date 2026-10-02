<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ElementType;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stringable;

/**
 * Captions one uploaded photo and lists the people, places and objects in
 * it that could become recurring cast and sets, described so an animator
 * could draw them.
 * Runs in the background as soon as a photo is added.
 */
class PhotoAnalyst implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Project $project,
        private readonly Image $photo,
    ) {}

    /**
     * The uploaded photo as sent to the model: the downsized reference copy
     * when it has been generated, otherwise the original.
     */
    public static function imageOf(Media $photo): Image
    {
        $reference = $photo->hasGeneratedConversion(Project::REFERENCE);

        return Image::fromStorage(
            $reference ? $photo->getPathRelativeToRoot(Project::REFERENCE) : $photo->getPathRelativeToRoot(),
            $reference ? ($photo->conversions_disk ?? $photo->disk) : $photo->disk,
        );
    }

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
            You look at one reference photo for an animated production and list what in it could recur in the shots as a person, a place or an object.

            Project: {$this->project->title}
            Description: {$this->project->description}

            First give "caption": one sentence of at most 25 words saying what the photo shows, factual, with the shapes, colours and markings an artist would need.

            Then list the items. For each item give:
            - "type": "person", "place" or "object".
            - "name": two to four words, such as "Security guard", "Main gate" or "Stan Tug 1606".
            - "description": one sentence on how it looks: shape, colours, clothing, markings, materials. Factual, no opinions, no names of real people.
            List at most eight items, the most useful first. Leave out background clutter. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'caption' => $schema->string()->required(),
            'items' => $schema->array()
                ->items($schema->object([
                    'type' => $schema->string()->enum(ElementType::collect()->map->value->all())->required(),
                    'name' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                ]))
                ->max(8)
                ->required(),
        ];
    }

    public function promptText(): string
    {
        return 'List the people, places and objects in this photo.';
    }

    /**
     * @return list<Image>
     */
    public function attachments(): array
    {
        return [$this->photo];
    }
}
