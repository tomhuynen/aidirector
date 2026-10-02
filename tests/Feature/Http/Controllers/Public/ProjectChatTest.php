<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ProjectPurpose;
use App\Jobs\AnalyzePhoto;
use App\Models\Director;
use App\Models\Generation;
use App\Models\Project;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
                ->where('chatUrl', route('public.projects.chat'))
                ->where('uploadUrl', route('public.uploads.store'))
                ->where('resume', null));
    });
});

describe('chat', function () {
    it('starts a remembered conversation on the first turn', function () {
        ProjectIntake::fake([
            ['reply' => 'Sounds good. Is this an explainer?', 'description' => 'A short film about posting a letter, for first-time senders.', 'purpose' => null, 'title' => null, 'ask' => null, 'done' => false],
        ]);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => 'A short film about posting a letter'])
            ->assertSuccessful()
            ->assertJsonPath('reply', 'Sounds good. Is this an explainer?')
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

    it('creates the project once description, purpose and title are settled', function () {
        ProjectIntake::fake([
            ['reply' => 'What shall we call it?', 'description' => 'How to post a letter, for first-time senders.', 'purpose' => 'explainer', 'title' => null, 'ask' => null, 'done' => false],
            ['reply' => 'Mailbox explainer it is.', 'description' => 'How to post a letter, for first-time senders.', 'purpose' => 'explainer', 'title' => '  Mailbox explainer ', 'ask' => 'photos', 'done' => false],
        ]);

        $conversationId = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'A short film'])
            ->assertSuccessful()
            ->assertJsonPath('project', null)
            ->json('conversation');

        expect(Project::query()->count())->toBe(0);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Call it Mailbox explainer'])
            ->assertSuccessful()
            ->assertJsonPath('conversation', $conversationId)
            ->assertJsonPath('reply', 'Mailbox explainer it is.')
            ->assertJsonPath('ask', 'photos')
            ->assertJsonPath('done', false)
            ->assertJsonPath('photos', []);

        $project = Project::query()->firstOrFail();

        expect($project->title)->toBe('Mailbox explainer')
            ->and($project->description)->toBe('How to post a letter, for first-time senders.')
            ->and($project->purpose)->toBe(ProjectPurpose::EXPLAINER)
            ->and($project->aspect_ratio)->toBe(AspectRatio::PORTRAIT)
            ->and($project->director_id)->toBe($this->director->id)
            ->and($project->conversation_id)->toBe($conversationId)
            ->and($project->conversation->title)->toBe('Mailbox explainer')
            ->and($response->json('project.id'))->toBe($project->sqid)
            ->and($response->json('project.url'))->toBe(route('public.projects.view', $project))
            ->and($response->json('project.styleRoundsUrl'))->toBe(route('public.projects.style.round', $project))
            ->and(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);

        ProjectIntake::assertPromptedTimes(2);
    });

    it('sets up a social short in portrait too', function () {
        ProjectIntake::fake([
            ['reply' => 'Ready.', 'description' => 'A ten second gag about a cat and a mailbox.', 'purpose' => 'social-short', 'title' => 'Cat vs Mailbox', 'ask' => 'photos', 'done' => false],
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'Cat vs Mailbox, a social short'])
            ->assertSuccessful();

        expect(Project::query()->firstOrFail()->aspect_ratio)->toBe(AspectRatio::PORTRAIT);
    });

    it('returns the existing project instead of creating a second one', function () {
        ProjectIntake::fake([
            ['reply' => 'Mailbox it is.', 'description' => 'Posting a letter.', 'purpose' => 'explainer', 'title' => 'Mailbox', 'ask' => 'photos', 'done' => false],
            ['reply' => 'Still Mailbox.', 'description' => 'Posting a parcel.', 'purpose' => 'commercial', 'title' => 'Mailbox again', 'ask' => null, 'done' => true],
        ]);

        $conversationId = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'Mailbox'])
            ->assertSuccessful()
            ->json('conversation');

        $project = Project::query()->firstOrFail();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Thanks'])
            ->assertSuccessful()
            ->assertJsonPath('project.id', $project->sqid)
            // The agent says done, but there is no style or cast and sets yet.
            ->assertJsonPath('done', false);

        expect(Project::query()->count())->toBe(1)
            ->and($project->fresh()->title)->toBe('Mailbox')
            ->and($project->fresh()->purpose)->toBe(ProjectPurpose::EXPLAINER);
    });

    it('waits until every field is settled and valid', function (array $turn) {
        ProjectIntake::fake([$turn]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'A'])
            ->assertSuccessful()
            ->assertJsonPath('project', null);

        expect(Project::query()->count())->toBe(0);
    })->with([
        'title too short' => [['reply' => 'Hm.', 'description' => 'Posting a letter.', 'purpose' => 'explainer', 'title' => 'A', 'ask' => null, 'done' => false]],
        'no title yet' => [['reply' => 'Hm.', 'description' => 'Posting a letter.', 'purpose' => 'explainer', 'title' => null, 'ask' => null, 'done' => false]],
        'no description yet' => [['reply' => 'Hm.', 'description' => null, 'purpose' => 'explainer', 'title' => 'Mailbox', 'ask' => null, 'done' => false]],
        'blank description' => [['reply' => 'Hm.', 'description' => '  ', 'purpose' => 'explainer', 'title' => 'Mailbox', 'ask' => null, 'done' => false]],
        'no purpose yet' => [['reply' => 'Hm.', 'description' => 'Posting a letter.', 'purpose' => null, 'title' => 'Mailbox', 'ask' => null, 'done' => false]],
        'unknown purpose' => [['reply' => 'Hm.', 'description' => 'Posting a letter.', 'purpose' => 'musical', 'title' => 'Mailbox', 'ask' => null, 'done' => false]],
    ]);

    it('refuses to continue another director\'s conversation', function () {
        ProjectIntake::fake([
            ['reply' => 'Hello.', 'description' => null, 'purpose' => null, 'title' => null, 'ask' => null, 'done' => false],
        ]);

        $other = Director::factory()->create();
        $conversationId = (new ProjectIntake())->forUser($other)->prompt('hi', provider: 'openrouter', model: 'test')->conversationId;

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'hijack'])
            ->assertForbidden();

        ProjectIntake::assertPromptedTimes(1);
    });

    describe('photos', function () {
        beforeEach(function () {
            Storage::fake(Disk::TENANT->value);
            Queue::fake([AnalyzePhoto::class]);
            ProjectIntake::fake([
                ['reply' => 'Hi.', 'description' => 'd', 'purpose' => 'explainer', 'title' => 't', 'ask' => 'photos', 'done' => false],
            ]);

            $this->project = Project::factory()->ownedBy($this->director)->create();
            $this->conversationId = (new ProjectIntake())->forUser($this->director)->prompt('hi', provider: 'openrouter', model: 'test')->conversationId;
            $this->project->update(['conversation_id' => $this->conversationId]);

            $this->uploads = [
                Upload::fromFile(UploadedFile::fake()->image('tug.jpg', 2400, 1600)),
                Upload::fromFile(UploadedFile::fake()->image('yard.png', 800, 600)),
            ];
        });

        it('claims uploads as content references, queues their analysis and answers without waiting for it', function () {
            ProjectIntake::fake([
                ['reply' => 'Got your two photos. More?', 'description' => 'd', 'purpose' => 'explainer', 'title' => 't', 'ask' => 'photos', 'done' => false],
            ]);

            $response = actingAs($this->director, 'director')
                ->postJson(route('public.projects.chat'), [
                    'conversation' => $this->conversationId,
                    'message' => 'Here are two',
                    'uploads' => [$this->uploads[0]->sqid, $this->uploads[1]->sqid],
                ])
                ->assertSuccessful()
                ->assertJsonPath('reply', 'Got your two photos. More?')
                ->assertJsonPath('photos.0.caption', null);

            $media = $this->project->fresh()->getMedia(Project::CONTENT_REFERENCES);

            expect($media)->toHaveCount(2)
                ->and($media[0]->file_name)->toBe('tug.jpg')
                ->and($media[1]->file_name)->toBe('yard.png')
                ->and($media[0]->sqid)->toBe($response->json('photos.0.id'))
                ->and(Upload::query()->count())->toBe(0);

            Storage::disk(Disk::TENANT->value)->assertExists($media[0]->getPathRelativeToRoot(Project::REFERENCE));

            [$width, $height] = getimagesize(Storage::disk(Disk::TENANT->value)->path($media[0]->getPathRelativeToRoot(Project::REFERENCE)));

            expect(max($width, $height))->toBe(Project::REFERENCE_MAX_EDGE);

            ProjectIntake::assertPrompted(fn(AgentPrompt $prompt) => $prompt->prompt === "Here are two\n\nThe director added 2 photos. They are being analysed in the background.");

            Queue::assertPushed(AnalyzePhoto::class, 2);
        });

        it('sends a note alone when photos come without text', function () {
            ProjectIntake::fake([
                ['reply' => 'Thanks.', 'description' => 'd', 'purpose' => 'explainer', 'title' => 't', 'ask' => 'photos', 'done' => false],
            ]);

            actingAs($this->director, 'director')
                ->postJson(route('public.projects.chat'), [
                    'conversation' => $this->conversationId,
                    'message' => '',
                    'uploads' => [$this->uploads[0]->sqid],
                ])
                ->assertSuccessful();

            ProjectIntake::assertPrompted(fn(AgentPrompt $prompt) => $prompt->prompt === 'The director added 1 photo. It is being analysed in the background.');
        });

        it('refuses photos before the project exists', function () {
            ProjectIntake::fake();

            actingAs($this->director, 'director')
                ->postJson(route('public.projects.chat'), ['message' => 'Look', 'uploads' => [$this->uploads[0]->sqid]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('uploads');

            expect(Upload::query()->count())->toBe(2);

            ProjectIntake::assertPromptedTimes(1);
        });

        it('rejects unknown uploads and non-images', function () {
            ProjectIntake::fake();
            $pdf = Upload::fromFile(UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'));

            actingAs($this->director, 'director')
                ->postJson(route('public.projects.chat'), ['conversation' => $this->conversationId, 'message' => 'Look', 'uploads' => ['nope', $pdf->sqid]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['uploads.0', 'uploads.1']);

            ProjectIntake::assertPromptedTimes(1);
        });
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
        'too many uploads' => [['message' => 'hi', 'uploads' => array_fill(0, 15, 'x')], 'uploads'],
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
