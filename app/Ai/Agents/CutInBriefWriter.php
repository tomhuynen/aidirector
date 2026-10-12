<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns the moment a shot ends on into what the next shot's camera sees when
 * it cuts in on that moment, such as a close-up at a truck window: the image
 * model copies the camera of the picture it is given, so where the new camera
 * stands in that scene, and what lies behind its subject from there, is
 * spelled out in words first.
 */
class CutInBriefWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        /** The shot the picture is from, such as "SH230". */
        private readonly string $before,
        /** This shot's kind and framing, such as "close-up". */
        private readonly string $framing,
        private readonly string $spot,
        /** What keyframe 1 of this shot shows. */
        private readonly string $first,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get the moment a shot of an animated film ends on. The next shot cuts in on that same moment from its own camera, as its plan describes. Work out where that camera stands in the scene of the picture and what it sees.

            Work out the geometry first. Place the new camera in the scene of the picture, next to the people and things in it, where the plan puts it, such as at the driver's window where the officer stands, and turn it towards its subject. Then decide what lies behind the subject from there: a camera beside the scene, at someone's side or at a window, looks across it in another direction than the first camera, so what was behind the subject in the picture is often beside or behind this camera now, and another side of the scene shows instead, such as the far side of the cab and the lane beyond it. Left and right swap when it looks the other way.

            brief: three to five short sentences. First where the camera stands in the scene of the picture and which way it looks. Then what is behind the subject in its frame, and where. Then what of the picture is beside or behind this camera and must not show behind the subject. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        return implode("\n", [
            "The attached image is the last moment of {$this->before}, seen from that shot's camera.",
            "The next shot is a {$this->framing} that cuts in on this moment from its own camera.",
            "Where it is: {$this->spot}",
            "What its first keyframe shows: {$this->first}",
        ]);
    }
}
