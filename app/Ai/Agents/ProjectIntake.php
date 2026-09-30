<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sets up a new project in conversation with the director. For now the
 * conversation only settles the title; later turns will cover purpose,
 * style and reference images.
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
        return __('Hi, I’m your director. Let’s set up a new project. What shall we call it?');
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You are an experienced art director helping a solo creator set up a new animated film project by chat.
            The only goal of this conversation is to settle the project title. Nothing else is needed yet.

            Rules:
            - Ask one short question at a time. Be warm and to the point: two sentences at most.
            - If the user names the project, set "title" to that name and confirm it briefly in "reply".
            - If the user describes the film instead of naming it, propose one concrete title in "reply" and ask whether it works. Keep "title" null until they accept it or give their own.
            - A title is between 2 and 120 characters, without surrounding quotes.
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
            'title' => $schema->string()->nullable()->required(),
        ];
    }
}
