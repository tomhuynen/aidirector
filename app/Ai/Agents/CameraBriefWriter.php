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
 * Turns a place seen from one camera into what another camera in that place
 * sees: the image model copies the camera of a picture it is given, so the
 * new camera position, and where every fixed thing lands in its frame, is
 * spelled out in words first.
 */
class CameraBriefWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly string $place,
        private readonly string $view,
        private readonly ?string $map = null,
        /** What an earlier shot left behind in the place, such as a landed helicopter, in terms of the place. */
        private readonly ?string $left = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You get a place of an animated film, seen from one camera. Another shot shows this same place from another camera, which its view describes. Work out what that other camera sees.

            Work out the geometry first, then follow the view. Place the new camera in the place where the view puts it and turn it the way the view says. Then, for every fixed thing, decide from where it stands in the place whether it is in front of this camera, and where: something in front of the new camera is in its frame, also when it is far away; only something behind or beside the camera is out of its frame. Things that were near the first camera are far away when the camera looks back from the other end, and left and right swap when it looks the other way. When the view's own words about a thing do not fit this geometry, the geometry wins.

            brief: three to five short sentences. First where the camera stands and which way it looks, in the place itself, such as "at the far end of the corridor by the open door, looking back towards the near end". Then every fixed thing of the place this camera sees, where it lands in its frame now: left or right of the frame, near or far, large or small; and what is out of its frame. Only fixed things, no people. Write in English.
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
        return implode("\n", array_filter([
            "The attached image is {$this->place}, seen from the first camera.",
            $this->map !== null ? "Where things stand in it: {$this->map}" : null,
            $this->left !== null ? "Left there by an earlier shot, also part of the place now: {$this->left} Say where it lands in this camera's frame and which side of it this camera sees." : null,
            "The other shot's view: {$this->view}",
        ]));
    }
}
