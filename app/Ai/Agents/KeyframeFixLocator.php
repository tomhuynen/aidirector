<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Finds where each problem in a keyframe is, before it is fixed: the box
 * the fix belongs in, which of the cast it is about, and what the box shows
 * once it is right. The layout model then changes only those boxes.
 */
class KeyframeFixLocator implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $problems  what is wrong with the keyframe, one problem each
     * @param  list<string>  $cast  the names of the people and objects of the keyframe
     */
    public function __construct(
        private readonly Keyframe $keyframe,
        private readonly array $problems,
        private readonly array $cast,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You find where problems are in a keyframe of an animated film, the attached image, so only that part is changed to fix them.

            For each problem, give:
            - problem: the problem, as given.
            - box: the part of the image the fix belongs in, as top, left, bottom and right on a 0 to 1000 grid of the frame, from the top left. Tight around what has to change, with a little margin; the whole frame only when the problem is about the whole image, such as the light or the colours.
            - member: the name of the one of the cast the problem is about, exactly as listed, such as a person whose helmet lost its logo; empty when it is about something else, such as a sign, the floor or a part of the place.
            - looks: true when the problem is how they look compared with their picture, such as their clothing, colours, headwear or a logo that went missing; false when it is their pose, what they hold or wear at this moment, or where they are, such as one arm in a vest.
            - shows: what that part shows once it is right, in one plain sentence, such as "the blue hard hat with the logo on the front, as in his picture".
            TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fixes' => $schema->array()->items($schema->object([
                'problem' => $schema->string()->required(),
                'box' => $schema->object([
                    'top' => $schema->integer()->required(),
                    'left' => $schema->integer()->required(),
                    'bottom' => $schema->integer()->required(),
                    'right' => $schema->integer()->required(),
                ])->required(),
                'member' => $schema->string()->required(),
                'looks' => $schema->boolean()->required(),
                'shows' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    public function promptFor(): string
    {
        $layout = KeyframeLayout::fromArray($this->keyframe->layout);
        $cast = array_map(function (string $name) use ($layout) {
            $member = $layout->member($name);

            return "- {$name}" . ($member === null ? '' : ', at [' . implode(', ', $member['box']) . ']');
        }, $this->cast);

        return implode("\n", [
            'What this keyframe shows: ' . $this->keyframe->fullDescription(),
            '',
            'The cast in it:',
            ...($cast === [] ? ['(none)'] : $cast),
            '',
            'The problems:',
            ...array_map(fn(string $problem) => "- {$problem}", $this->problems),
        ]);
    }

    /**
     * The fixes from the answer: a box on the grid for each, the cast member
     * by their exact name when it is about one of them, whether it is about
     * how they look, and what it shows.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array{problem: string, box: array{0: int, 1: int, 2: int, 3: int}, member: string|null, looks: bool, shows: string}>
     */
    public function fixesFrom(array $answer): array
    {
        $fixes = [];

        foreach ((array) ($answer['fixes'] ?? []) as $fix) {
            $box = KeyframeLayout::box($fix['box'] ?? null);
            $shows = trim((string) ($fix['shows'] ?? ''));

            if ($box === null || $shows === '') {
                continue;
            }

            $named = trim((string) ($fix['member'] ?? ''));
            $member = collect($this->cast)->first(fn(string $name) => $named !== '' && mb_strtolower($name) === mb_strtolower($named));
            $fixes[] = ['problem' => trim((string) ($fix['problem'] ?? '')), 'box' => $box, 'member' => $member, 'looks' => $member !== null && (bool) ($fix['looks'] ?? false), 'shows' => $shows];
        }

        return $fixes;
    }
}
