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
 * behaviour readable, the steps distinguishable, and the people, place and
 * objects continuous from one keyframe to the next.
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
            You review the keyframes of one shot of an animated e-learning film, the way a learner will see them. The images are the keyframes in order; the prompt says which keyframe each image is. Always refer to keyframes by their number, not by the image's place in the list.

            Judge the sequence as a whole against what the shot teaches:
            - Can a viewer see the point of the shot, such as the danger and the safe behaviour, without reading any text?
            - Is what each keyframe's description says about positions and distances, such as where someone stands relative to a line or a hazard, clearly visible and readable?
            - Does every keyframe show a step a viewer can see? Two keyframes in a row that look almost the same, where only a small detail such as a hand angle changes, is an issue: name the step that cannot be seen.
            - Does the movement go the way the storyline says? Someone who enters a place moves into it, someone who leaves moves out of it, someone who walks towards something ends up nearer to it. Moving the opposite way, such as walking out towards the camera while the storyline says she walks into the hall, is an issue.
            - Is everything that matters large enough to read at a glance on a phone? The people should be big in the frame and the object the story turns on, such as a cigarette, a key or a buckle, clearly visible. People that are small in a wide view with a lot of empty space, or an object of a few pixels, is an issue; name the keyframes.
            - Does the storyline happen in the keyframes? A step it names, such as someone entering, leaving or noticing something, that only happens between two keyframes or cannot be seen is an issue.
            - Does the place stay still? The camera does not move, so fixed parts such as markings and painted lines on the floor, doors and signs keep the same position, shape and angle in every keyframe; a floor line that runs differently is an issue.
            - Does lettering stay the same throughout? Words, logos or numbers that appear or disappear between keyframes, on clothing, helmets, vehicles or walls, are an issue.

            Judge whether the keyframes read as one continuous moment, because a break makes a viewer lose the thread:
            - Every person is recognisable as the same person throughout: face, hair, clothing and colours.
            - The place and the camera stay the same unless the keyframes say they change; a sign, vehicle or door does not move, appear or disappear between keyframes.
            - Objects stay where they were left and change state in an order that makes sense: what is picked up is held, what is opened stays open.
            - Positions and distances change in the direction the steps describe.
            Ignore small details such as exact hand positions or expressions, and matters of taste.

            Output:
            - clear: true when the shot gets its point across.
            - notes: when it does not, one entry per issue; empty when clear.
              - keyframes: the numbers of the keyframes that must change to solve it, such as [3, 4]. Always give them, also for something across the shot: give the keyframes that differ from keyframe 1. Keyframe 1 is the reference, so give it only when the problem is in keyframe 1 itself. Empty only when no keyframe can solve it.
              - note: the issue in one plain sentence for the director, such as "She still stands on the line, so stepping back cannot be seen." or "The yellow floor line runs at another angle than in keyframe 1." It is shown on each of those keyframes, so do not start with their numbers.
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
            'notes' => $schema->array()
                ->items($schema->object([
                    'keyframes' => $schema->array()->items($schema->integer())->required(),
                    'note' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }

    public function promptFor(): string
    {
        $plans = $this->shot->storylineKeyframes();
        $lines = $this->keyframes->values()->map(function (Keyframe $keyframe) use ($plans) {
            $plan = $plans[$keyframe->position - 1] ?? [];

            if ($plan['copied'] ?? false) {
                return "{$keyframe->position}. A copy of another keyframe that the director has not described yet: judge it only as a step between the keyframes around it.";
            }

            return "{$keyframe->position}. {$keyframe->title}: {$keyframe->description}";
        })->join("\n");

        $storyline = $this->shot->chosenStoryline()['storyline'] ?? '';

        // A keyframe without an image is left out, so image numbers and keyframe numbers can differ.
        $images = $this->keyframes->values()
            ->map(fn(Keyframe $keyframe, int $index) => 'Image ' . ($index + 1) . " is keyframe {$keyframe->position}.")
            ->join(' ');

        // A montage is a row of separate stills: each has its own place, so the checks on one still place do not apply.
        $montage = $this->shot->isMontage()
            ? "\n\nThis shot is a montage: every keyframe is a separate still with its own place and camera, joined with crossfades. Ignore the checks on the place staying still and on one continuous moment. Judge instead whether each still shows its own part of the takeaway at a glance, without text, boards or pictograms, whether the stills in order say the takeaway, and whether the people stay recognisable as the same people."
            : '';

        return "The shot teaches: {$this->shot->takeaway}\nStoryline: {$storyline}{$montage}\n\nKeyframes:\n{$lines}\n\n{$images}";
    }
}
