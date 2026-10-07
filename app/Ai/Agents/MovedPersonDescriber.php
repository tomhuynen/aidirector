<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * After the director moved a person in a keyframe by hand, keeps the plan
 * and the story true to the image: rewrites the description, the
 * storyline and, when needed, the title, as little
 * as the move asks, so the check, the review and the video prompt judge the
 * keyframe by what it now shows. Warns only when the takeaway no longer
 * comes across.
 */
class MovedPersonDescriber implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Keyframe $keyframe,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            A director moved a person in a keyframe of an animated shot by hand, and the pose may have been redrawn for the new spot. You see the keyframe as it is now. Keep its plan true to the image.

            - description: the keyframe's description rewritten so it says what the image shows now. Keep its wording and style, and change only what the move changed: where the person stands, where they face or look, their pose and gesture. Say where things are from the camera's point of view: left or right in the frame, nearer or further.
            - storyline: the shot's storyline with the part about this moment changed as little as the move asks, for example "steps into the red zone" becoming "steps to the edge of the red zone". Every other word stays the same. The same text when the storyline already fits.
            - title: the keyframe's title, changed only when it no longer fits what the image shows; otherwise the same title. Two to four words.
            - warning: only when the shot's takeaway no longer comes across with the move, one short sentence that says why. Small changes of position that the storyline and title absorb need no warning: leave it empty.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->required(),
            'storyline' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'warning' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        $storyline = $this->keyframe->shot->chosenStoryline()['storyline'] ?? '';

        return implode("\n", array_filter([
            "Keyframe {$this->keyframe->position}: {$this->keyframe->title}",
            "Description before the move: {$this->keyframe->description}",
            "Takeaway of the shot: {$this->keyframe->shot->takeaway}",
            $storyline !== '' ? "Storyline of the shot: {$storyline}" : null,
        ]));
    }
}
