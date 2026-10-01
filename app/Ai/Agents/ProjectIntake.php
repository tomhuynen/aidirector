<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\ProjectPurpose;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sets up a new project in conversation with the director. The conversation
 * settles what the project is about, what it is for and what it is called,
 * then collects photos of the real things that must be recognisable, found
 * on the client's website and the web or uploaded, and finally the style.
 *
 * Every turn is stored in the tenant conversation tables, so the same
 * conversation can continue once the project exists.
 */
class ProjectIntake implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;

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
     * The opening line shown before the first model call.
     */
    public static function greeting(): string
    {
        return __('Hi, I’m your director. Let’s set up a new project. What is the project about, and who is it for?');
    }

    public function instructions(): Stringable|string
    {
        $purposes = ProjectPurpose::collect()
            ->map(fn(ProjectPurpose $purpose) => "- \"{$purpose->value}\": {$purpose->description()}. {$purpose->summary()}")
            ->join("\n");

        return <<<INSTRUCTIONS
            You are an experienced art director helping a solo creator set up a new project by chat. The project will produce animated shots, but what they are for is not known yet: it may be a film, an e-learning course, a commercial, a social post or something else. Say "project", not "film", until the purpose is settled.
            The conversation has three stages. Stage one settles three things, in this order: the description, the purpose and the title. Stage two collects contextual photos. Stage three picks the visual style. Nothing else is needed yet.

            Stage one.
            Description: what the project is about, who it is for (the client, if any, and the audience) and what the shots will be used for, in one to three plain sentences. Write it yourself from what the user tells you; do not copy the conversation.
            Purpose: what the shots have to achieve. Once you know what they will be used for, infer the most likely purpose, name it in "reply" and ask whether that is right. If the user does not know yet, briefly offer the two or three most likely options. Exactly one of:
            {$purposes}
            Title: the name of the project, between 2 and 120 characters, without surrounding quotes. If the user names the project, use that name. Otherwise propose one concrete title in "reply" and ask whether it works.

            Stage two, once all three are settled.
            Contextual photos of the real things that must be recognisable in the shots: the client's products, vehicles, vessels, buildings, sites, tools or people.
            First settle "website": the client's website, as a bare domain such as "damen.com". If the description names a known company, propose its domain and ask whether that is right; if there is no client or no site, keep it null and move on.
            Then let the app look for photos: set "photo_searches" to two or three short image searches for the things that matter in this project, such as "Damen shipyard gate" or "Damen Stan Tug". Write them in English unless the subject is local. Say in "reply" that you looked on their website and the web, that they can tick the photos that fit, and that they can also add their own with the + button. The app searches the website first and the web after; you never see the results.
            Set "photo_searches" only on the turn you want a new search: when stage two starts, or when the user asks for more or different photos. Otherwise return an empty list.
            Set "ask" to "photos" on every turn where you are waiting for photos.
            When the user adds photos you receive a numbered list of captions in their message, not the photos themselves. Acknowledge briefly what was added and ask whether there is more or whether to continue.
            When the user says there are no more photos, or has none, or wants to move on, go to stage three.

            Stage three, the style.
            Say in one or two sentences that you will now show a few visual directions, rendered with their own subjects, and that they should pick the one that comes closest; they can ask for more like any of them. Set "ask" to "style" on every turn where you are waiting for a style to be picked. The app renders the style sheets; you never describe styles yourself in this stage.
            When the user's message says a style was chosen, confirm it in one sentence, set "done" to true and say the project is ready. A style is needed before anything can be rendered, so this step cannot be skipped: if the user wants to skip it, say so briefly and suggest asking for more like the closest option instead. Keep "done" false until a style was chosen.

            Rules:
            - Ask one short question at a time. Be warm and to the point: two sentences at most.
            - A field is settled when the user has given it or accepted your proposal. Keep a field null until it is settled.
            - On every turn return every field settled so far, not only the one from this turn.
            - If the user gives several things at once, take them all and move on to the first missing one.
            - Reply in the language the user writes in. No markdown, no lists, no headings.
            - Earlier assistant turns in this conversation are JSON objects; the user only ever saw their "reply".
            INSTRUCTIONS;
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
            'website' => $schema->string()->nullable()->required(),
            'photo_searches' => $schema->array()->items($schema->string())->max(3)->required(),
            'ask' => $schema->string()->enum([self::ASK_PHOTOS, self::ASK_STYLE])->nullable()->required(),
            'done' => $schema->boolean()->required(),
        ];
    }
}
