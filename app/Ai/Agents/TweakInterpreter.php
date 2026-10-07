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
 * cannot work out from a vague request. When the change alters what the
 * keyframe shows, it also rewrites the description the check judges by.
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

        $cast = $this->keyframe->elements->pluck('name')->join(', ') ?: 'none';

        return <<<INSTRUCTIONS
            You prepare edit instructions for an image model that adjusts keyframes of an animated shot. Image models follow precise, spatial instructions well and vague ones badly, so your job is to make the director's request precise.

            {$context}
            What the keyframe should show: {$this->keyframe->fullDescription()}
            Cast and sets in the keyframe: {$cast}
            {$this->shotRules()}

            Look at the image and rewrite the director's request as an edit instruction:
            - Say where things are and where they move from the camera's point of view: left or right in the frame, nearer to or further from the camera, into or out of the place. Never write "forward", "backward", "in front of him" or "behind her": they depend on which way a person faces and get turned around.
            - Use the character's own point of view only for their body: which shoulder, which hand, which way the head or body turns, and how far.
            - Say what the viewer should see afterwards, for example the back of the head, an empty hand, a closed door.
            - Name what must stay exactly the same: the feet and stance unless the change needs them, the other hand, the props, the camera, the framing, the background, the lighting and the style.
            - Change only what the director asked for. Do not add new ideas, such as where in the frame someone stands or a pose, unless the request needs it.
            - When someone enters, leaves or walks somewhere, show them mid-step in the direction they go, not standing still.
            - Write three to five short plain sentences in English, in the present tense, as a description of the edited image. No lists, no markdown.

            Also choose how the change is made:
            - edit: a local change to something already in the picture, such as clothing, a hand, an expression, an object's colour or a sign. The image is edited and everything else stays exactly the same.
            - redraw: the change moves people or objects through the scene, changes distances or how close someone is to something, or changes the viewpoint, framing or composition. Editing cannot do that, so the keyframe is drawn again with the request.

            Finally, keep the keyframe's description true. The automatic check judges the image by it, so it must say what the image shows after the change:
            - description: when the change alters what the keyframe shows, rewrite "What the keyframe should show" in the same style so it describes the edited image. That includes a person or object removed or added, someone in another place, a different action, and also where someone looks, a gesture such as a thumbs up or a wave, and a pose: the keyframe may be drawn again from its description later, and the change must survive that. Leave it empty only for a fix that does not change what a viewer sees happening, such as a colour, a line on the floor or a sign.
            - absent: the names from the cast and sets that are no longer in the picture after the change, such as a person who is removed. Empty when everyone stays.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'instruction' => $schema->string()->required(),
            'approach' => $schema->string()->enum(['edit', 'redraw'])->required(),
            'description' => $schema->string()->required(),
            'absent' => $schema->array()->items($schema->string())->required(),
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

    /**
     * The rules the director set for this shot; the instruction never asks for something that breaks one.
     */
    private function shotRules(): string
    {
        $rules = $this->keyframe->shot->rulesBrief();

        return $rules === '' ? '' : "Rules for this shot; never write an instruction that breaks one:\n{$rules}";
    }
}
