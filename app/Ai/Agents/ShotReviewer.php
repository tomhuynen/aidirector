<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks at all keyframes of a shot together, the way a viewer will, and says
 * whether the sequence makes the takeaway clear: the danger and the safe
 * behaviour readable, the steps distinguishable.
 */
class ShotReviewer implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  Collection<int, Keyframe>  $keyframes  in order, each with a render
     */
    public function __construct(
        private readonly Shot $shot,
        private readonly Collection $keyframes,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You review the keyframes of one shot of an animated e-learning film, the way a learner will see them. The images are the keyframes in order.

            Judge the sequence as a whole against what the shot teaches:
            - Can a viewer see the point of the shot, such as the danger and the safe behaviour, without reading any text?
            - Is what each keyframe must show clearly visible, with distances and positions readable?
            - Do the keyframes differ enough to tell the steps apart?
            Ignore small details such as exact hand positions or expressions, and matters of taste.

            Output:
            - clear: true when the shot gets its point across.
            - notes: when it does not, each issue in one plain sentence for the director, naming the keyframe by number, such as "In keyframes 3 and 4 she still stands on the line, so stepping back cannot be seen." Empty when clear.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clear' => $schema->boolean()->required(),
            'notes' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function promptFor(): string
    {
        $plans = $this->shot->storylineKeyframes();
        $lines = $this->keyframes->values()->map(function (Keyframe $keyframe) use ($plans) {
            $mustShow = $plans[$keyframe->position - 1]['must_show'] ?? null;

            return "{$keyframe->position}. {$keyframe->title}: {$keyframe->description}" . (filled($mustShow) ? " Must show: {$mustShow}" : '');
        })->join("\n");

        $storyline = $this->shot->chosenStoryline()['storyline'] ?? '';

        return "The shot teaches: {$this->shot->takeaway}\nStoryline: {$storyline}\n\nKeyframes:\n{$lines}";
    }
}
