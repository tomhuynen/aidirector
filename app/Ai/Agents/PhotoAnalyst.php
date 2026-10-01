<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\ElementType;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Lists the people, places and objects in one uploaded photo that could
 * become recurring cast and sets, described so an animator could draw them.
 * Runs in the background as soon as a photo is added.
 */
class PhotoAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly Project $project,
        private readonly Image $photo,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
            You look at one reference photo for an animated production and list what in it could recur in the shots as a person, a place or an object.

            Project: {$this->project->title}
            Description: {$this->project->description}

            For each item give:
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
