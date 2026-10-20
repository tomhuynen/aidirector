<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\RenderCapabilities;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Shot;
use App\Support\Shots\SceneDirection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reviews a sequence the scene director laid out against its story, the
 * layouts of its places and what the rendering mode can play: whether each
 * story event is played, each claim has evidence, and the shots agree with
 * each other, so the director gets one repair before the sequence is shown.
 */
class SequenceReviewer implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
        private readonly string $story,
        private readonly SceneDirection $direction,
    ) {}

    public function instructions(): Stringable|string
    {
        $capabilities = RenderCapabilities::brief();

        return <<<INSTRUCTIONS
            You review a sequence of shots that a scene director laid out for a short story of an animated e-learning film, before it is shown to the film's director. You read text only: the story, the shots the viewer saw before, the layouts of the places, and the sequence as JSON.

            What the rendering of the shots can and cannot do:
            {$capabilities}

            Check the sequence, shot by shot:
            1. Every explicit event of the story is played in the action of a shot. An event that is only in facts, purpose, notice, cut or the staging is not played. An insert shows its one thing while nothing moves; it depicts seeing that thing when the shot before ends on the look at it.
            2. Each fact number in shows and each event number in depicts has evidence in the action or the staging of that shot.
            3. The start frame of each shot is the state before its action and allows it: the person stands, faces, looks and holds what the action starts from.
            4. A reaction has a cause the viewer can identify: in the composition of that shot, in an insert, or in a shot the viewer saw before.
            5. The cut of each shot refers only to what its action shows.
            6. An exit or other route follows open ground and an opening the place layout has.
            7. The sequence adds no outcome the story does not have, such as "she leaves the quay" for "she walks out of view".
            8. Adjacent shots agree on where each person stands, which way they face and look, which hand holds what and at what height, and the place and state of each object.
            9. The sequence follows what the rendering can do, as described above.

            Report only what breaks one of these checks; never report taste, wording or another valid way to film it.

            Output:
            - issues: each issue in one plain sentence that names the shot by its number and says what to fix, such as "Shot 2: the action never has her turn her head; add the head turn from the vessel to the sign." Empty when the sequence passes.
            - passes: true when issues is empty.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'passes' => $schema->boolean()->required(),
            'issues' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function promptFor(): string
    {
        $earlier = $this->shot->earlierShotsBrief();
        $sequence = json_encode($this->direction->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
            The story, in the director's words: {$this->story}

            Shots the viewer has seen before this sequence:
            {$earlier}

            Layouts of the places of the sequence, as their pictures show them:
            {$this->layouts()}

            The sequence:
            {$sequence}
            PROMPT;
    }

    /**
     * The layout of each place a frame of the sequence shows.
     */
    private function layouts(): string
    {
        $names = array_map(fn(string $name) => mb_strtolower($name), $this->direction->elementNames());

        return $this->shot->project->elements()->with('media')->where('type', ElementType::PLACE)->get()
            ->filter(fn(Element $place) => in_array(mb_strtolower($place->name), $names, true))
            ->map(fn(Element $place) => "- {$place->name}: " . ($place->layout() ?? '(not read yet)'))
            ->join("\n") ?: '(none)';
    }
}
