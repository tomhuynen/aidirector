<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\StagingRules;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeStaging;
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
 * keyframe shows, it also stages the keyframe anew, so the description the
 * check judges by says what the image shows.
 */
class TweakInterpreter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<Image>  $images  the current render, then the start frame when the end frame is edited
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $images,
    ) {}

    public function instructions(): Stringable|string
    {
        $context = count($this->images) > 1
            ? 'You get two images: first the keyframe to edit, the end frame, then the start frame of the same shot, for reference only.'
            : 'You get one image: the keyframe to edit.';

        $cast = $this->keyframe->elements->pluck('name')->join(', ') ?: 'none';
        $staging = StagingRules::rules();

        return <<<INSTRUCTIONS
            You prepare edit instructions for an image model that adjusts keyframes of an animated shot. Image models follow precise, spatial instructions well and vague ones badly, so your job is to make the director's request precise.

            {$context}
            What the keyframe should show: {$this->keyframe->fullDescription()}
            Cast and sets in the keyframe: {$cast}
            How the keyframe is staged now:
            {$this->keyframe->staged()->outline()}

            Look at the image and rewrite the director's request as an edit instruction:
            - Say where things are and where they move from the camera's point of view: left or right in the frame, nearer to or further from the camera, into or out of the place. Never write "forward", "backward", "in front of him" or "behind her": they depend on which way a person faces and get turned around.
            - Use the character's own point of view only for their body: which shoulder, which hand, which way the head or body turns, and how far.
            - Say what the viewer should see afterwards, for example the back of the head, an empty hand, a closed door.
            - When the change makes someone look or aim elsewhere, say which way the head and which way the body face afterwards.
            - Name what must stay exactly the same: where they stand, the other hand unless the turn moves it, the props, the camera, the framing, the background, the lighting and the style.
            - Never ask for text: no letters, words, numbers, labels or ID details on anything, also not to make an object recognisable. An object is recognisable by its shape, colour, size and where it is; say that instead. Lettering that is already in the picture stays as it is.
            - Change only what the director asked for. Do not add new ideas, such as where in the frame someone stands or a pose, unless the request needs it.
            - When someone enters, leaves or walks somewhere, show them mid-step in the direction they go, not standing still.
            - Write three to five short plain sentences in English, in the present tense, as a description of the edited image. No lists, no markdown.

            Also choose how the change is made:
            - edit: a local change to something already in the picture, such as clothing, a hand, an expression, an object's colour or a sign. The image is edited and everything else stays exactly the same.
            - redraw: the change moves people or objects through the scene, changes distances or how close someone is to something, or changes the viewpoint, framing or composition. Editing cannot do that, so the keyframe is drawn again with the request.

            Finally, keep the keyframe's staging true. Its description is built from the staging and the automatic check judges the image by it, so it must say what the image shows after the change:
            - restage: true when the change alters what the keyframe shows: a person or object removed or added, someone in another place, a different action, and also where someone looks, a gesture such as a thumbs up or a wave, and a pose: the keyframe may be drawn again from its description later, and the change must survive that. False only for a fix that does not change what a viewer sees happening, such as a colour, a line on the floor or a sign.
            - staging: the staging after the change: the fields as they are now, changed only as far as the change goes, by these rules:
            {$staging}
            - The staging never asks for text, labels or ID details either.
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
            'restage' => $schema->boolean()->required(),
            'staging' => $schema->object(KeyframeStaging::schema($schema))->required(),
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
}
