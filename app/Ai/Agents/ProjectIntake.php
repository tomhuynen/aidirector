<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ElementRoundStatus;
use App\Enums\ElementType;
use App\Enums\ProjectPurpose;
use App\Models\ElementRound;
use App\Models\Project;
use App\Support\Elements\PhotoInventory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sets up a new project in conversation with the director. The conversation
 * settles what the project is about, what it is for and what it is called,
 * then collects photos of the real things that must be recognisable, picks
 * the style and finally sets up the cast and sets.
 *
 * Every turn is stored in the tenant conversation tables, so the same
 * conversation can continue once the project exists.
 */
class ProjectIntake implements Agent, Conversational, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;
    use SetsReasoningEffort;

    /**
     * The agent is waiting for photos; the chat shows how to add them.
     */
    public const ASK_PHOTOS = 'photos';

    /**
     * The agent is waiting for a style to be picked; the chat runs the
     * style exploration.
     */
    public const ASK_STYLE = 'style';

    /**
     * The agent is waiting for cast and sets to be picked or for the brief of
     * the current category; the chat shows the suggestion grid.
     */
    public const ASK_ELEMENTS = 'elements';

    /**
     * The project being set up, once it exists. Its photos, style and cast
     * and sets status are given to the agent on every turn.
     */
    public function __construct(
        private readonly ?Project $project = null,
    ) {}

    /**
     * The opening line shown before the first model call.
     */
    public static function greeting(): string
    {
        return __('Hi, I’m your director. Let’s set up a new project. What is the project about, and who is it for?');
    }

    public function instructions(): Stringable|string
    {
        $suggestions = (int) Config::get('pipeline.element_suggestions_count');

        $purposes = ProjectPurpose::collect()
            ->map(fn(ProjectPurpose $purpose) => "- \"{$purpose->value}\": {$purpose->description()}. {$purpose->summary()}")
            ->join("\n");

        return <<<INSTRUCTIONS
            You are an experienced art director helping a solo creator set up a new project by chat. The project will produce animated shots, but what they are for is not known yet: it may be a film, an e-learning course, a commercial, a social post or something else. Say "project", not "film", until the purpose is settled.
            The conversation has four stages. Stage one settles three things, in this order: the description, the purpose and the title. Stage two collects contextual photos. Stage three picks the visual style. Stage four sets up the cast and sets: the recurring people, places and objects.

            Stage one.
            Description: what the project is about, who it is for (the client, if any, and the audience) and what the shots will be used for, in one to three plain sentences. Write it yourself from what the user tells you; do not copy the conversation.
            Your second question, right after the user first describes the project, is whether they have a document with the functional design, a brief or a script, which they can paste into the message box or add as a PDF, RTF or text file with the + button next to it. Skip it if they already shared one. When a shared document appears in a message, between <<< and >>>, read it closely, take the description, purpose and title from it where it gives them, and use it for everything that follows. Acknowledge it in one sentence; do not summarise it back.
            Purpose: what the shots have to achieve. Once you know what they will be used for, infer the most likely purpose, name it in "reply" and ask whether that is right. If the user does not know yet, briefly offer the two or three most likely options. Exactly one of:
            {$purposes}
            Title: the name of the project, between 2 and 120 characters, without surrounding quotes. If the user names the project, use that name. Otherwise propose one concrete title in "reply" and ask whether it works.

            Stage two, once all three are settled.
            Ask for photos of the real things that must be recognisable in the shots: the client's products, vehicles, vessels, buildings, sites, tools or people. Give one or two concrete examples that fit this project. Tell the user to add them with the + button next to the message box, and that they can also skip this. Set "ask" to "photos" on every turn where you are waiting for photos.
            When the user adds photos, their message ends with a note saying how many were added; you do not see the photos yourself. Their captions and contents appear under "What the app knows" once the background analysis is done. Acknowledge briefly how many were added and ask whether there are more or whether to continue.
            When the user says there are no more photos, or has none, or wants to move on, go to stage three.

            Stage three, the style.
            Say in one or two sentences that you will now show a few visual directions, rendered with their own subjects, and that they should pick the one that comes closest; they can ask for more like any of them. Set "ask" to "style" on every turn where you are waiting for a style to be picked. The app renders the style sheets; you never describe styles yourself in this stage.
            When the user's message says a style was chosen, confirm it in one sentence and go to stage four. A style is needed before anything can be rendered, so this step cannot be skipped: if the user wants to skip it, say so briefly and suggest asking for more like the closest option instead.

            Stage four, the cast and sets. This stage is optional.
            Start by asking in one short question whether the user wants to set up the recurring people, places and objects now, so every shot draws them the same way, or skip it and add them later while making shots. If they skip, set "skip_elements" to true and go to the last step.
            If they want to set them up:
            First check whether you know enough to suggest people, places and objects: who the audience is, where the shots take place and what happens in them. If something important is missing, ask about it in one question before you begin.
            As soon as you know enough, set "prepare" to a first brief for every category that is "not started": two to four sentences each on what to suggest, from the description, the conversation and what the photos show. The app starts drawing those suggestions in the background, so they are ready when you get to the category. Do this once; categories that are prepared say so under "What the app knows".
            Then go through the categories one at a time, in this order: people, places, objects. For each category:
            1. Tell the user briefly what you already know for it, from the description, the conversation and the uploaded photos listed under "What the app knows", and ask whether that is right and whether they want to add anything.
            2. If the answer leaves the category unclear, ask one follow-up question: for people what kind of people and what they do (visitors, engineers, a manager, customers); for places which locations; for objects which things matter.
            3. When the category is clear, set "element_round" to the category ("person", "place" or "object"). If the category was prepared and the user's answers did not change what to suggest, set "use_prepared" to true: the prepared suggestions are shown right away. Otherwise set "use_prepared" to false and give a new "brief" of two to four sentences with every detail the user gave and what the photos show; the prepared suggestions are replaced. Always fill "brief". Say in "reply" that here are {$suggestions} suggestions to pick from, drawn in the chosen style.
            The app renders the suggestions; the user ticks the ones to keep. Their next message says which were picked, or that they skip the category. Then move on to the next category. If the user wants to skip a category, set "skip" to that category and move on. A category is settled once it was picked from or skipped; the status is listed under "What the app knows".
            Set "ask" to "elements" on every turn in stage four.
            When all three categories are settled, ask once whether anything is missing: another person, place or object that should look the same in every shot. If the user names something, set "element_round" to its category with "use_prepared" false and a "brief" for just that, and handle the pick as before. Do not ask again after that.
            When the user has nothing to add, go to the last step.

            Last step, the shots.
            Ask in one short question whether you should generate the shots for them. If yes, set "shots" to the shots of the project in story order, as many as the material needs to cover every point once: a short brief may need three, a full design document twenty or more, up to 30. Each shot has a "takeaway": the one point a viewer must learn from that shot, in one plain sentence, and a "context": one or two sentences on where it happens and what goes on. Base them on the description, the shared document and the conversation, and give each shot its own point. Say in one sentence that the shots are being created and set "done" to true. If no, say in one sentence that the project is ready and set "done" to true. Keep "done" false until this question is answered, and leave "shots" null on every other turn.

            Rules:
            - Ask one short question at a time. Be warm and to the point: two sentences at most.
            - A field is settled when the user has given it or accepted your proposal. Keep a field null until it is settled.
            - On every turn return every field settled so far, not only the one from this turn.
            - If the user gives several things at once, take them all and move on to the first missing one.
            - Reply in the language the user writes in. No markdown, no lists, no headings.
            - Earlier assistant turns in this conversation are JSON objects; the user only ever saw their "reply".
            - "prepare", "element_round", "skip" and "skip_elements" are actions for this turn only: set them only on the turn you prepare, start or skip a category, otherwise null (or false).
            {$this->knowledge()}
            INSTRUCTIONS;
    }

    /**
     * What the app knows about the project right now: the photos and what
     * was found in them, the chosen style and the cast and sets status.
     */
    private function knowledge(): string
    {
        if ($this->project === null) {
            return '';
        }

        $project = $this->project;
        $rounds = $project->elementRounds()->with('suggestions')->get();
        $style = $project->styleReference() === null ? 'not chosen yet' : trim(($project->style['look'] ?? '') . ' ' . ($project->style['medium'] ?? ''));

        $status = ElementType::collect()
            ->map(fn(ElementType $type) => "- {$type->plural()}: " . $this->categoryStatus($project, $type, $rounds->where('type', $type)))
            ->join("\n");

        $photos = PhotoInventory::describe($project);

        return <<<KNOWLEDGE

            What the app knows:
            Style: {$style}
            Uploaded photos:
            {$photos}
            Cast and sets:
            {$status}
            KNOWLEDGE;
    }

    /**
     * One category's status for the agent: settled with what was picked,
     * a round waiting for a pick, or a round prepared in the background.
     *
     * @param  Collection<int, ElementRound>  $rounds
     */
    private function categoryStatus(Project $project, ElementType $type, Collection $rounds): string
    {
        $picked = $project->elements()->where('type', $type)->pluck('name')->join(', ');
        $settled = $rounds->contains(fn(ElementRound $round) => $round->status->settlesCategory());
        $shown = $rounds->filter(fn(ElementRound $round) => $round->presented_at !== null)->last();
        $prepared = $rounds->first(fn(ElementRound $round) => $round->isPrepared());

        $parts = array_filter([
            match (true) {
                $settled && $picked !== '' => "settled, picked: {$picked}",
                $settled => 'skipped',
                default => null,
            },
            match (true) {
                $shown?->isOpen() === true => 'suggestions shown, waiting for the user to pick',
                $shown?->status === ElementRoundStatus::FAILED => 'the suggestions failed; ask the user whether to try again',
                default => null,
            },
            $prepared === null ? null : match ($prepared->status) {
                ElementRoundStatus::FAILED => 'preparing failed; give a new brief when you get here',
                default => "prepared in the background, not shown yet, from the brief: \"{$prepared->brief}\"",
            },
        ]);

        return $parts === [] ? 'not started' : implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
            'purpose' => $schema->string()->enum(ProjectPurpose::collect()->map->value->all())->nullable()->required(),
            'title' => $schema->string()->nullable()->required(),
            'ask' => $schema->string()->enum([self::ASK_PHOTOS, self::ASK_STYLE, self::ASK_ELEMENTS])->nullable()->required(),
            'prepare' => $schema->array()->items($schema->object([
                'type' => $schema->string()->enum(ElementType::collect()->map->value->all())->required(),
                'brief' => $schema->string()->required(),
            ]))->nullable()->required(),
            'element_round' => $schema->object([
                'type' => $schema->string()->enum(ElementType::collect()->map->value->all())->required(),
                'brief' => $schema->string()->required(),
                'use_prepared' => $schema->boolean()->required(),
            ])->nullable()->required(),
            'skip' => $schema->string()->enum(ElementType::collect()->map->value->all())->nullable()->required(),
            'skip_elements' => $schema->boolean()->required(),
            'shots' => $schema->array()->items($schema->object([
                'takeaway' => $schema->string()->required(),
                'context' => $schema->string()->required(),
            ]))->nullable()->required(),
            'done' => $schema->boolean()->required(),
        ];
    }
}
