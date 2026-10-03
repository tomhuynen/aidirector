<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use App\Models\Keyframe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks at a freshly drawn keyframe before the director sees it and lists
 * clear mistakes against its plan and its references, with one instruction
 * that fixes them. Only obvious errors count; taste is left to the director.
 */
class KeyframeChecker implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<Element>  $pictured  the cast and sets attached after the keyframe, in order
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly bool $withFirstKeyframe,
        private readonly array $pictured,
        private readonly ?string $mustShow = null,
    ) {}

    public function instructions(): Stringable|string
    {
        $images = ['The first image is the keyframe to check.'];

        if ($this->withFirstKeyframe) {
            $images[] = 'The second image is keyframe 1 of the same shot: the place, its fixed parts and the camera must match it.';
        }

        $offset = $this->withFirstKeyframe ? 3 : 2;

        foreach ($this->pictured as $index => $element) {
            $images[] = 'Image ' . ($offset + $index) . " shows how {$element->name} ({$element->type->value}) must look.";
        }

        $imageList = implode("\n", $images);

        return <<<INSTRUCTIONS
            You check keyframes of an animated e-learning film before the director sees them. Report only clear mistakes a viewer would notice; never report matters of taste.

            {$imageList}

            The shot teaches: {$this->keyframe->shot->takeaway}

            Check, most important first:
            - Must show: {$this->mustShowLine()}
            - The action: the pose, which hand holds what, and the state of the objects match what the keyframe should show.
            - The cast: every person and object from the references looks like its reference: face, hair, clothing, headwear, colours.
            - The place: logos, signs, doors, windows and vehicles that are in keyframe 1 are still there and in the same place; nothing new appears that is not in the description.
            - Physical sense: objects are whole and of a normal size, nothing floats or sits on a wall where it cannot be, no duplicated or merged objects or limbs.
            - Text: no added words, letters or captions; logos that belong to the place are fine.

            Output:
            - must_show_visible: true when the must show is clearly visible, or when there is none.
            - passes: true when there is no clear mistake.
            - problems: each clear mistake in one short, plain sentence for the director, as you would say it to a colleague, such as "The container still hangs over her path." No technical terms; empty when it passes.
            - fix: one short instruction for the image model that corrects all problems and keeps everything else; empty when it passes.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'must_show_visible' => $schema->boolean()->required(),
            'passes' => $schema->boolean()->required(),
            'problems' => $schema->array()->items($schema->string())->required(),
            'fix' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return "What the keyframe should show: {$this->keyframe->description}";
    }

    private function mustShowLine(): string
    {
        return filled($this->mustShow)
            ? "{$this->mustShow} This is what the keyframe is for; if a viewer cannot see it at a glance, that is a mistake."
            : 'nothing specific for this keyframe.';
    }
}
