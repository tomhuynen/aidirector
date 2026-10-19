<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\BodyRules;
use App\Ai\Briefs\DirectingRules;
use App\Ai\Briefs\StagingRules;
use App\Ai\Briefs\TextRules;
use App\Ai\Briefs\VideoLimitsBrief;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Models\Shot;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Directs a story told in the plan chat into a sequence of shots, each with
 * its purpose, its start frame, the act and its end frame, built around the
 * decisive change of the scene. The first shot is the shot whose chat it is;
 * the others follow it.
 */
class SceneDirector implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
        private readonly string $story,
        /** @var list<string> */
        private readonly array $leftOut = [],
    ) {}

    public function instructions(): Stringable|string
    {
        $project = $this->shot->project;
        $directing = DirectingRules::brief();
        $staging = StagingRules::rules();
        $limits = VideoLimitsBrief::brief();
        $text = TextRules::forPlanning($project);
        $max = (int) Config::get('pipeline.video.max_duration');
        $tempo = BodyRules::NO_TEMPO;

        return <<<INSTRUCTIONS
            {$directing}

            Project: {$project->title}
            Project description: {$project->description}
            Aspect ratio: {$this->shot->aspectRatio()->value}.

            Cast and sets of this project, the only people, places and objects the shots may use, by their exact names:
            {$project->elementsBrief()}
            Never describe how someone looks; their picture decides that when the frames are drawn.

            Text in the images: {$text}

            What a shot cannot do, each with why and what to do instead:
            {$limits}

            The start frame and the end frame of every shot are staged in these fields:
            {$staging}
            - Spatial fact: the one spatial fact the shot depends on in that frame, in one sentence a viewer could check at a glance, kept apart from the place and the cast; empty when there is none.
            - Elements: the exact names of the cast and sets visible in the frame.

            Output:
            - title: the scene in two to four words.
            - change: the decisive change, in one sentence.
            - facts: the facts a viewer must see to understand the story, numbered in order, three to six short lines.
            - context: what the viewer must already know for the scene to mean what it should, one line each; empty when nothing.
            - must_not: what must never happen in any frame, such as "the hand touches the bag", short phrases in the words the frames would use; empty when nothing.
            - new_elements: a person, place or object the story needs that the cast and sets do not have, such as a bag when there is none, to offer the director to make: a new short name, not the name of an existing one, its type and one or two sentences on how it looks, without text in it. Use that name in the staging of the frames that show it. Empty when the cast and sets have everything.
            - shots: the sequence, two to six shots. Per shot: title in two to four words; kind; purpose, what the audience must understand; notice, what they must notice; start, the start frame, which for every shot after the first is the end frame of the shot before it with people, seen from this shot's camera; action, the one act between the frames in two or three plain sentences in the present tense; end, the end frame; seconds, 2 to {$max}; cut, what motivates the cut at its end; shows, the numbers of the facts this shot shows.
            - In action and the staging of the frames: {$tempo}
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $frame = fn() => $schema->object([
            'title' => $schema->string()->required(),
            ...KeyframeStaging::schema($schema),
            'spatial' => $schema->string()->required(),
            'elements' => $schema->array()->items($schema->string())->required(),
        ])->required();

        return [
            'title' => $schema->string()->required(),
            'change' => $schema->string()->required(),
            'facts' => $schema->array()->items($schema->string())->max(8)->required(),
            'context' => $schema->array()->items($schema->string())->max(4)->required(),
            'must_not' => $schema->array()->items($schema->string())->max(4)->required(),
            'new_elements' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'type' => $schema->string()->enum(['person', 'place', 'object'])->required(),
                'description' => $schema->string()->required(),
            ]))->max(4)->required(),
            'shots' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'kind' => $schema->string()->enum([ShotKind::SCENE->value, ShotKind::MEDIUM->value, ShotKind::CLOSE_UP->value, ShotKind::INSERT->value])->required(),
                'purpose' => $schema->string()->required(),
                'notice' => $schema->string()->required(),
                'start' => $frame(),
                'action' => $schema->string()->required(),
                'end' => $frame(),
                'seconds' => $schema->integer()->min(1)->required(),
                'cut' => $schema->string()->required(),
                'shows' => $schema->array()->items($schema->integer()->min(1))->required(),
            ]))->min(1)->max(6)->required(),
        ];
    }

    public function promptFor(): string
    {
        $earlier = Shot::query()->where('project_id', $this->shot->project_id)->whereNull('merged_into_id')
            ->where('position', '<', $this->shot->position)->orderBy('position')->get()
            ->map(fn(Shot $shot) => "- {$shot->code()}: " . (trim((string) ($shot->chosen_storyline['storyline'] ?? '')) ?: trim((string) $shot->idea) ?: $shot->title))
            ->join("\n") ?: '(none)';
        $voiceOver = trim((string) $this->shot->voice_over) ?: '(none)';
        $leftOut = $this->leftOut === [] ? '' : "\nThe director left these out of the cast and sets; never use them, and choose another of the cast and sets where the story needs one: " . implode(', ', $this->leftOut) . "\n";

        return <<<PROMPT
            Purpose of the film: {$this->shot->purpose()->value}
            Shots the viewer has seen before this scene:
            {$earlier}
            Voice-over of this shot so far: {$voiceOver}
            {$leftOut}

            The story to direct, in the director's words: {$this->story}
            PROMPT;
    }
}
