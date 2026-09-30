<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Models\Director;
use App\Models\Generation;
use App\Models\Project;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
});

describe('create page', function () {
    it('opens the intake chat with a greeting', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.create'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->component('projects/create')
                ->where('greeting', ProjectIntake::greeting())
                ->where('chatUrl', route('public.projects.chat')));
    });
});

describe('chat', function () {
    it('starts a remembered conversation on the first turn', function () {
        ProjectIntake::fake([
            ['reply' => 'Sounds good. What shall we call it?', 'title' => null],
        ]);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => 'A short film about posting a letter'])
            ->assertSuccessful()
            ->assertJsonPath('reply', 'Sounds good. What shall we call it?')
            ->assertJsonPath('project', null);

        $conversationId = $response->json('conversation');

        expect($conversationId)->toBeString();

        $conversation = Conversation::query()->whereKey($conversationId)->firstOrFail();

        expect($conversation->participant_type)->toBe('director')
            ->and($conversation->participant_id)->toBe($this->director->id)
            ->and($conversation->title)->toBe('A short film about posting a letter')
            ->and(ConversationMessage::query()->where('conversation_id', $conversationId)->orderBy('created_at')->pluck('role')->all())->toBe(['user', 'assistant'])
            ->and(Project::query()->count())->toBe(0);

        $generation = Generation::query()->firstOrFail();

        expect($generation->director_id)->toBe($this->director->id)
            ->and($generation->kind)->toBe('chat')
            ->and($generation->generatable)->toBeInstanceOf(Director::class)
            ->and($generation->error)->toBeNull();

        ProjectIntake::assertPrompted(fn(AgentPrompt $prompt) => $prompt->prompt === 'A short film about posting a letter');
    });

    it('creates the project once the agent has settled a title', function () {
        ProjectIntake::fake([
            ['reply' => 'What shall we call it?', 'title' => null],
            ['reply' => 'Mailbox explainer it is.', 'title' => '  Mailbox explainer '],
        ]);

        $conversationId = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'A short film'])
            ->assertSuccessful()
            ->json('conversation');

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Call it Mailbox explainer'])
            ->assertSuccessful()
            ->assertJsonPath('conversation', $conversationId)
            ->assertJsonPath('reply', 'Mailbox explainer it is.');

        $project = Project::query()->firstOrFail();

        expect($project->title)->toBe('Mailbox explainer')
            ->and($project->director_id)->toBe($this->director->id)
            ->and($project->conversation_id)->toBe($conversationId)
            ->and($project->conversation->title)->toBe('Mailbox explainer')
            ->and($response->json('project.id'))->toBe($project->sqid)
            ->and($response->json('project.url'))->toBe(route('public.projects.view', $project))
            ->and(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);

        ProjectIntake::assertPromptedTimes(2);
    });

    it('returns the existing project instead of creating a second one', function () {
        ProjectIntake::fake([
            ['reply' => 'Mailbox it is.', 'title' => 'Mailbox'],
            ['reply' => 'Still Mailbox.', 'title' => 'Mailbox again'],
        ]);

        $conversationId = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'Mailbox'])
            ->assertSuccessful()
            ->json('conversation');

        $project = Project::query()->firstOrFail();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Thanks'])
            ->assertSuccessful()
            ->assertJsonPath('project.id', $project->sqid);

        expect(Project::query()->count())->toBe(1)
            ->and($project->fresh()->title)->toBe('Mailbox');
    });

    it('ignores titles that are too short', function () {
        ProjectIntake::fake([
            ['reply' => 'Hm.', 'title' => 'A'],
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'A'])
            ->assertSuccessful()
            ->assertJsonPath('project', null);

        expect(Project::query()->count())->toBe(0);
    });

    it('refuses to continue another director\'s conversation', function () {
        ProjectIntake::fake([
            ['reply' => 'Hello.', 'title' => null],
        ]);

        $other = Director::factory()->create();
        $conversationId = (new ProjectIntake())->forUser($other)->prompt('hi', provider: 'openrouter', model: 'test')->conversationId;

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'hijack'])
            ->assertForbidden();

        ProjectIntake::assertPromptedTimes(1);
    });

    it('validates the input', function (array $payload, string $field) {
        ProjectIntake::fake();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        ProjectIntake::assertNeverPrompted();
    })->with([
        'missing message' => [['message' => ''], 'message'],
        'message too long' => [['message' => str_repeat('a', 2001)], 'message'],
        'malformed conversation' => [['conversation' => 'not-a-uuid', 'message' => 'hi'], 'conversation'],
    ]);

    it('reports a failing provider and logs the error', function () {
        ProjectIntake::fake(function () {
            throw new RuntimeException('Provider down');
        });

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'hi'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'The director is unavailable right now. Please try again.');

        $generation = Generation::query()->firstOrFail();

        expect($generation->kind)->toBe('chat')
            ->and($generation->error)->toBe('Provider down')
            ->and(Conversation::query()->count())->toBe(0);
    });

    it('requires a signed-in director', function () {
        $this->postJson(route('public.projects.chat'), ['message' => 'hi'])->assertUnauthorized();
    });
});
