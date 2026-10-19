<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stringable;

/**
 * Reads the layout of a place from one version of its picture: where the
 * walls, doors, exits and fixed furniture are, seen from the camera of that
 * picture, so the directors can stage people in it without seeing it.
 */
class PlaceLayoutReader implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Element $place,
        private readonly Media $picture,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You look at the picture of a place in an animated film and describe its layout, so a director who cannot see the picture can stage people in it.

            Describe what the camera of this picture sees, with left and right as in the picture:
            - What is on the left side, on the right side, in the front and at the back.
            - Every door, opening and exit: on which side, and whether it is open or closed.
            - Fixed furniture and fittings people can stand at, sit at or walk around.
            - Where open floor is, where people can stand and walk.
            Write two to four plain sentences in the present tense. Leave out colours, light, style and people; the picture decides how it looks. Never guess what is outside the picture. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'layout' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "The place: {$this->place->promptLine()}";
    }

    /**
     * @return list<Image>
     */
    public function attachments(): array
    {
        return [Image::fromStorage($this->picture->getPathRelativeToRoot(), $this->picture->disk)];
    }
}
