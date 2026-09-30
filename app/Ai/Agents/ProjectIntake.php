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
 * settles what the project is about, what it is for and what it is called;
 * look and feel come later.
 *
 * Every turn is stored in the tenant conversation tables, so the same
 * conversation can continue once the project exists.
 */
class ProjectIntake implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;

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
            The goal of this conversation is to settle three things, in this order: the description, the purpose and the title. Nothing else is needed yet.

            Description: what the project is about, who it is for (the client, if any, and the audience) and what the shots will be used for, in one to three plain sentences. Write it yourself from what the user tells you; do not copy the conversation.
            Purpose: what the shots have to achieve. Once you know what they will be used for, infer the most likely purpose, name it in "reply" and ask whether that is right. If the user does not know yet, briefly offer the two or three most likely options. Exactly one of:
            {$purposes}
            Title: the name of the project, between 2 and 120 characters, without surrounding quotes. If the user names the project, use that name. Otherwise propose one concrete title in "reply" and ask whether it works.

            Rules:
            - Ask one short question at a time. Be warm and to the point: two sentences at most.
            - A field is settled when the user has given it or accepted your proposal. Keep a field null until it is settled.
            - On every turn return every field settled so far, not only the one from this turn.
            - If the user gives several things at once, take them all and move on to the first missing one.
            - When all three are settled, confirm them briefly in "reply" and say the project is ready.
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
        ];
    }
}
