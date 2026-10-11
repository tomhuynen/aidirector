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

    public function __construct(private readonly Shot $shot) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the empty place a shot of an animated film is drawn on, seen from the camera that never moves, and the keyframes planned for it before the place was drawn. Every keyframe is drawn on this exact picture, so the plan must fit it.

            - Look where the fixed things the keyframes name stand in the picture, such as a bin, a door, a pillar, a sign or a line on the floor: left, right or in the middle of the frame, near or far.
            - Where a keyframe puts such a thing on another side, or has someone stand, face, walk or point in a direction that does not fit where the things are, rewrite only those words so they fit the picture. Someone who walks away from the bin goes to the side where the bin is not; someone who goes to the door goes towards where the door is. Keep the story, the order, the people, the actions and every other word the same.
            - Name sides from the camera's point of view: on the left or the right of the frame, nearer to or further from the camera. Name hands by the side of the frame they are on.
            - keyframes: every keyframe in the same order, with its description and spatial fact, changed or as it was.
            - note: when you changed anything, one short sentence for the director on what and why, such as "The bin stands on the left by the pillar, so she now walks away to the right." Empty when the plan already fits.
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
        ];
    }

    public function promptFor(): string
    {
        $keyframes = collect($this->shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['description']}" . (filled($keyframe['spatial'] ?? null) ? "\n   Spatial fact: {$keyframe['spatial']}" : ''))
            ->join("\n");

        return "The attached image is the empty place.\n\nThe keyframes:\n{$keyframes}";
    }
}
