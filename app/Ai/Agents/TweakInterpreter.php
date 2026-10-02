<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns the director's short adjustment ("he should look back at the sign")
 * into a precise edit instruction for the image model. It looks at the
 * current render, so it can say where things are in the picture, which way
 * the character turns and what should become visible, which image models
 * cannot work out from a vague request.
 */
class TweakInterpreter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<Image>  $images  the current render, then the keyframe before it if there is one
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $images,
    ) {}

    public function instructions(): Stringable|string
    {
        $context = count($this->images) > 1
            ? 'You get two images: first the keyframe to edit, then the keyframe directly before it in the same shot, for reference only.'
            : 'You get one image: the keyframe to edit.';

        return <<<INSTRUCTIONS
            You prepare edit instructions for an image model that adjusts keyframes of an animated shot. Image models follow precise, spatial instructions well and vague ones badly, so your job is to make the director's request precise.

            {$context}
            What the keyframe should show: {$this->keyframe->description}

            Look at the image and rewrite the director's request as an edit instruction:
            - Say where the relevant things are in the picture (left or right of the frame, in front of or behind the character).
            - Describe the change from the character's own point of view where it matters: which shoulder, which hand, which way the head or body turns, and how far.
            - Say what the viewer should see afterwards, for example the back of the head, an empty hand, a closed door.
            - Name what must stay exactly the same: the feet and stance unless the change needs them, the other hand, the props, the camera, the framing, the background, the lighting and the style.
            - Change only what the director asked for. Do not add new ideas.
            - Write three to five short plain sentences in English, in the present tense, as a description of the edited image. No lists, no markdown.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'instruction' => $schema->string()->required(),
        ];
    }

    public function promptFor(string $request): string
    {
        return "The director's request: {$request}";
    }

    /**
     * @return list<Image>
     */
    public function attachments(): array
    {
        return $this->images;
    }
}
