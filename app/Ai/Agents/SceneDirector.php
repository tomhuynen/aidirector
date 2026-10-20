<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\BodyRules;
use App\Ai\Briefs\DirectingRules;
use App\Ai\Briefs\RenderCapabilities;
use App\Ai\Briefs\StagingRules;
use App\Ai\Briefs\TextRules;
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
 * its purpose, its camera, its start frame, the action and its end frame,
 * built around the decisive change of the scene and depicting every event
 * of the story. The first shot is the shot whose chat it is; the others
 * follow it. Given the issues a review found in its previous answer, it
 * directs the story again to fix them.
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
        /** @var list<string> */
        private readonly array $issues = [],
        /** @var array<string, mixed>|null */
        private readonly ?array $previous = null,
    ) {}

    public function instructions(): Stringable|string
    {
        $project = $this->shot->project;
        $directing = DirectingRules::brief();
        $staging = StagingRules::rules();
        $rendering = RenderCapabilities::brief();
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

            {$rendering}

            The start and the expected end of every shot are staged in these fields:
            {$staging}
            - Spatial fact: the one spatial fact the shot depends on in that frame, in one sentence a viewer could check at a glance, kept apart from the place and the cast; empty when there is none.
            - Elements: the exact names of the cast and sets visible in the frame, always with the place of the cast and sets the shot plays in; the place field names that place by its exact name too.

            Output:
            - title: the scene in two to four words.
            - change: the decisive change, in one sentence.
            - facts: the facts a viewer must see to understand the story, numbered in order, three to six short lines.
            - events: every action the story names, in the story's order, numbered, one short line each in the story's own words where possible, such as "she turns her head" or "she walks out of view". Only what the story says happens: never an outcome, a place or a reason it does not have.
            - context: what the viewer must already know for the scene to mean what it should, one line each; empty when nothing, and never what this sequence shows itself.
            - must_not: what must never happen in any frame, such as "the hand touches the bag", short phrases in the words the frames would use; empty when nothing.
            - new_elements: a person, place or object the story needs that the cast and sets do not have, such as a bag when there is none, to offer the director to make: a new short name, not the name of an existing one, its type and one or two sentences on how it looks, without text in it. Use that name in the staging of the frames that show it. Empty when the cast and sets have everything.
            - shots: the sequence, two to six shots. Per shot:
              - title: two to four words.
              - kind: the size of the shot.
              - subject: what the frame is centred on: body, face, hands or object. A close-up with face shows a reaction that matters; a close-up with hands or object shows a small decisive action; an insert is always object.
              - purpose: what the audience must understand from this shot.
              - notice: what they must notice.
              - camera: where the camera stands by the landmarks of the place and which way it looks, in the place's own terms, not left or right of the frame, such as "on the quay walkway behind her, looking along the quay towards the moored vessel".
              - composition: what is in the foreground and in the background where that matters, such as "the sign on the metal wall panel in the left foreground, the vessel in the background".
              - reason: why this framing is needed for what the audience must understand at this point of the story.
              - start: the start frame, the state before the action of this shot, staged so that the action can happen from it; never a state that a part of the action has already reached.
              - action: what visibly happens, in the order it happens, in two to four plain sentences in the present tense, playable from the start frame. It holds every event this shot depicts, in causal order: what someone means to do, what they see, that they recognise it, what they do about it, the result. A turn of the head names what they look at first, what they turn to and where that is.
              - end: the end frame, the state the action is expected to end in.
              - seconds: 2 to {$max}.
              - cut: why the shot ends or cuts away; it names only what the action of this shot shows.
              - shows: the numbers of the facts the action or the frames of this shot show.
              - depicts: the numbers of the events the action of this shot shows. Every event is depicted by at least one shot.
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
            'events' => $schema->array()->items($schema->string())->min(1)->max(10)->required(),
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
                'subject' => $schema->string()->enum(ShotKind::SUBJECTS)->required(),
                'purpose' => $schema->string()->required(),
                'notice' => $schema->string()->required(),
                'camera' => $schema->string()->required(),
                'composition' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
                'start' => $frame(),
                'action' => $schema->string()->required(),
                'end' => $frame(),
                'seconds' => $schema->integer()->min(1)->required(),
                'cut' => $schema->string()->required(),
                'shows' => $schema->array()->items($schema->integer()->min(1))->required(),
                'depicts' => $schema->array()->items($schema->integer()->min(1))->required(),
            ]))->min(1)->max(6)->required(),
        ];
    }

    public function promptFor(): string
    {
        $earlier = $this->shot->earlierShotsBrief();
        $voiceOver = trim((string) $this->shot->voice_over) ?: '(none)';
        $leftOut = $this->leftOut === [] ? '' : "\nThe director left these out of the cast and sets; never use them, and choose another of the cast and sets where the story needs one: " . implode(', ', $this->leftOut) . "\n";
        $repair = '';

        if ($this->issues !== [] && $this->previous !== null) {
            $answer = json_encode($this->previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $issues = collect($this->issues)->map(fn(string $issue) => "- {$issue}")->join("\n");
            $repair = "\n\nYour previous direction of this story:\n{$answer}\n\nA review found these issues in it:\n{$issues}\nDirect the story again: fix these issues and keep everything of the previous direction that was right.";
        }

        return <<<PROMPT
            Purpose of the film: {$this->shot->purpose()->value}
            Shots the viewer has seen before this scene:
            {$earlier}
            Voice-over of this shot so far: {$voiceOver}
            {$leftOut}

            The story to direct, in the director's words: {$this->story}{$repair}
            PROMPT;
    }
}
