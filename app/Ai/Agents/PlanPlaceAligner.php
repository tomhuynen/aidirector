<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Matches the keyframes of a plan to the place they are drawn on: the plan is
 * written before the place exists, and a place is often drawn the other way
 * round, so the fixed things stand on the other side than the plan says.
 * Only where things are and where people stand, face and go is changed.
 */
class PlanPlaceAligner implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
        /** Where the fixed things stand, which way they face and the routes through the place, in plain lines. */
        private readonly string $layout = '',
        /** Whether the second image is the same view with the cast where they stand at the start, cut from the master of the sequence. */
        private readonly bool $withBlocking = false,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the empty place a shot of an animated film is drawn on, seen from the camera that never moves, and the keyframes planned for it before the place was drawn. Every keyframe is drawn on this exact picture, so the plan must fit it.

            - Look where the fixed things the keyframes name stand in the picture, such as a bin, a door, a pillar, a sign or a line on the floor: left, right or in the middle of the frame, near or far.
            - Where a keyframe puts such a thing on another side, or has someone stand, face, walk or point in a direction that does not fit where the things are, rewrite only those words so they fit the picture. Someone who walks away from the bin goes to the side where the bin is not; someone who goes to the door goes towards where the door is. Keep the story, the order, the people, the actions and every other word the same.
            - Check every keyframe against the geometry of the place, with the layout below and the picture:
              - On its route: what drives, rolls or walks stands on its lane, path or door and is lined up with it, its front the way it goes next, such as a truck in its lane with its nose towards the barrier, never turned across the lane.
              - Facing: who or what turns to something faces it from where they stand, such as a guard at a booth window that faces the lane.
              - Seen: from this camera, nothing that must be seen is hidden behind something nearer the camera, such as the guard and his booth behind a truck in the lane between them and the camera.
              - Reach: who touches, hands over or checks something stands close enough to it.
            - Rewrite only the words that break one of these so the keyframe fits, such as where someone stands or which way something faces.
            - When a second image shows the same view with the cast in it, that is where everyone stands at the start of this shot, as the director chose it: keyframe 1 keeps their spots and the way each of them faces there, such as a truck that comes towards the camera from beyond the barrier, and the later keyframes move on from there. Never turn someone or something around against that picture, and never move them to the other side of a barrier, line or door; rewrite the words to fit the picture instead.
            - Name sides from the camera's point of view: on the left or the right of the frame, nearer to or further from the camera. Name hands by the side of the frame they are on.
            - keyframes: every keyframe in the same order, with its description and spatial fact, changed or as it was.
            - note: when you changed anything, one short sentence for the director on what and why, such as "The bin stands on the left by the pillar, so she now walks away to the right." Empty when the plan already fits.
            - problem: when a keyframe cannot work from this camera however you word it, such as the guard and the booth hidden behind the truck while the truck must stay in its lane, one short sentence on what cannot be seen or done and what would fix it, such as another camera across the lane or the guard on the near side of the truck. Empty when everything fits after your changes.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyframes' => $schema->array()->items($schema->object([
                'description' => $schema->string()->required(),
                'spatial' => $schema->string()->required(),
            ]))->required(),
            'note' => $schema->string()->required(),
            'problem' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        $keyframes = collect($this->shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['description']}" . (filled($keyframe['spatial'] ?? null) ? "\n   Spatial fact: {$keyframe['spatial']}" : ''))
            ->join("\n");

        $images = $this->withBlocking
            ? 'The first attached image is the empty place; the second is the same view with the cast where they stand at the start of this shot.'
            : 'The attached image is the empty place.';

        return $images . ($this->layout !== '' ? "\n\nIts layout:\n{$this->layout}" : '') . "\n\nThe keyframes:\n{$keyframes}";
    }
}
