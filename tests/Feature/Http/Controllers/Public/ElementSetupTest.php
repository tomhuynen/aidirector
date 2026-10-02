<?php

declare(strict_types=1);

use App\Ai\Agents\ElementSuggester;
use App\Ai\Agents\PhotoAnalyst;
use App\Ai\Agents\ProjectIntake;
use App\Ai\ElementPainter;
use App\Ai\ProjectCoverPainter;
use App\Enums\CoverStatus;
use App\Enums\Disk;
use App\Enums\ElementRoundStatus;
use App\Enums\ElementSuggestionStatus;
use App\Enums\ElementType;
use App\Jobs\AnalyzePhoto;
use App\Jobs\GenerateElementSuggestions;
use App\Jobs\GenerateProjectCover;
use App\Jobs\RenderElementSuggestion;
use App\Models\Director;
use App\Models\Element;
use App\Models\ElementRound;
use App\Models\ElementSuggestion;
use App\Models\Project;
use App\Support\Elements\PhotoInventory;
use App\Support\Elements\StartElementRound;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A chat-created project with a pinned style, at the start of the cast and sets stage.
 */
function projectInElementStage(Director $director, Project $project): string
{
    ProjectIntake::fake([['reply' => 'Hi', 'ask' => null, 'done' => false]]);
    $conversationId = (new ProjectIntake())->forUser($director)->prompt('Damen', provider: 'openrouter', model: 'test')->conversationId;
    $project->update(['conversation_id' => $conversationId]);
    $project->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(Project::STYLE_REFERENCES);

    return $conversationId;
}

function pngBase64(): string
{
    return base64_encode(UploadedFile::fake()->image('render.png', 64, 64)->getContent());
}

describe('chat', function () {
    it('starts a category round when the agent has a brief', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ProjectIntake::fake([[
            'reply' => 'Preparing twelve people to pick from.',
            'ask' => 'elements', 'done' => false, 'skip' => null,
            'element_round' => ['type' => 'person', 'brief' => 'Visitors, contractors and a security guard at the gate.'],
        ]]);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Yes, and contractors too'])
            ->assertSuccessful()
            ->assertJsonPath('ask', 'elements')
            ->assertJsonPath('elementRound.type', 'person')
            ->assertJsonPath('elementRound.label', 'People')
            ->assertJsonPath('elementRound.status', 'suggesting')
            ->assertJsonPath('elementRound.options', []);

        $round = $this->project->elementRounds()->firstOrFail();

        expect($round->brief)->toBe('Visitors, contractors and a security guard at the gate.')
            ->and($response->json('elementRound.pollUrl'))->toBe(route('public.projects.elements.rounds.view', [$this->project, $round]));

        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->is($round));
    });

    it('does not start rounds before a style is pinned or while one is waiting for a pick', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ElementRound::factory()->for($this->project)->create();
        ProjectIntake::fake([[
            'reply' => 'Again?', 'ask' => 'elements', 'done' => false, 'skip' => null,
            'element_round' => ['type' => 'person', 'brief' => 'More people'],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'x'])
            ->assertSuccessful()
            ->assertJsonPath('elementRound', null);

        $this->project->clearMediaCollection(Project::STYLE_REFERENCES);
        ProjectIntake::fake([[
            'reply' => 'Places', 'ask' => 'elements', 'done' => false, 'skip' => null,
            'element_round' => ['type' => 'place', 'brief' => 'The gate'],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'x'])
            ->assertJsonPath('elementRound', null);

        Queue::assertNothingPushed();
    });

    it('skips a category and only finishes once style and every category are settled', function () {
        $conversationId = projectInElementStage($this->director, $this->project);
        ElementRound::factory()->for($this->project)->status(ElementRoundStatus::PICKED)->create();
        ElementRound::factory()->for($this->project)->type(ElementType::PLACE)->status(ElementRoundStatus::PICKED)->create();

        ProjectIntake::fake([
            ['reply' => 'Ready.', 'ask' => 'elements', 'done' => true, 'skip' => null, 'element_round' => null],
            ['reply' => 'Skipping objects. Ready.', 'ask' => null, 'done' => true, 'skip' => 'object', 'element_round' => null],
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'done?'])
            ->assertJsonPath('done', false);

        expect($this->project->fresh()->setup_completed_at)->toBeNull();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Skip objects'])
            ->assertJsonPath('done', true);

        expect($this->project->fresh()->setup_completed_at)->not->toBeNull()
            ->and($this->project->fresh()->needsSetup())->toBeFalse()
            ->and($this->project->elementRounds()->where('type', 'object')->firstOrFail()->status)->toBe(ElementRoundStatus::SKIPPED);
    });

    it('tells the agent what it knows: photos, findings and category status', function () {
        projectInElementStage($this->director, $this->project);
        $this->project->addMedia(UploadedFile::fake()->image('tug.jpg'))
            ->withCustomProperties([Project::CAPTION => 'A grey harbour tug.', PhotoInventory::PROPERTY => [['type' => 'object', 'name' => 'Stan Tug', 'description' => 'Grey tug with a red stripe.']]])
            ->toMediaCollection(Project::CONTENT_REFERENCES);
        $this->project->addMedia(UploadedFile::fake()->image('yard.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);
        ElementRound::factory()->for($this->project)->type(ElementType::PLACE)->status(ElementRoundStatus::SKIPPED)->create();
        Element::query()->create(['project_id' => $this->project->id, 'type' => ElementType::PERSON, 'name' => 'Guard', 'description' => 'd']);
        ElementRound::factory()->for($this->project)->status(ElementRoundStatus::PICKED)->create();

        $instructions = (string) (new ProjectIntake($this->project))->instructions();

        expect($instructions)->toContain('Photo 1: A grey harbour tug. Found: object: Stan Tug (Grey tug with a red stripe.).')
            ->toContain('Photo 2: no caption Found: still being analysed.')
            ->toContain('- People: settled, picked: Guard')
            ->toContain('- Places: skipped')
            ->toContain('- Objects: not started');
    });
});

describe('prepared rounds', function () {
    it('prepares every category in the background without showing it', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ProjectIntake::fake([[
            'reply' => 'Who are the people in it?', 'ask' => 'elements', 'done' => false, 'skip' => null, 'element_round' => null,
            'prepare' => [
                ['type' => 'person', 'brief' => 'Visitors and a guard.'],
                ['type' => 'place', 'brief' => 'The yard gate.'],
                ['type' => 'object', 'brief' => 'Hard hats and vests.'],
            ],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Yes, set them up'])
            ->assertSuccessful()
            ->assertJsonPath('elementRound', null);

        $rounds = $this->project->elementRounds()->get();

        expect($rounds)->toHaveCount(3)
            ->and($rounds->every(fn(ElementRound $round) => $round->isPrepared()))->toBeTrue()
            ->and($rounds->pluck('brief')->all())->toBe(['Visitors and a guard.', 'The yard gate.', 'Hard hats and vests.'])
            ->and($rounds->pluck('status')->all())->toBe([ElementRoundStatus::SUGGESTING, ElementRoundStatus::WAITING, ElementRoundStatus::WAITING]);

        // Only the people are written now; places and objects wait their turn.
        Queue::assertPushed(GenerateElementSuggestions::class, 1);
        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->type === ElementType::PERSON);

        $instructions = (string) (new ProjectIntake($this->project))->instructions();

        expect($instructions)->toContain('- People: prepared in the background, not shown yet, from the brief: "Visitors and a guard."');

        actingAs($this->director, 'director')
            ->get(route('public.projects.setup', $this->project))
            ->assertInertia(fn($page) => $page->where('resume.messages', fn($messages) => collect($messages)->doesntContain('kind', 'element-options')));
    });

    it('queues the renders in chat order: people, then places, then objects', function () {
        Queue::fake([GenerateElementSuggestions::class, RenderElementSuggestion::class]);
        $people = ElementRound::factory()->for($this->project)->prepared()->status(ElementRoundStatus::SUGGESTING)->create();
        $objects = ElementRound::factory()->for($this->project)->prepared()->type(ElementType::OBJECT)->status(ElementRoundStatus::WAITING)->create();
        $places = ElementRound::factory()->for($this->project)->prepared()->type(ElementType::PLACE)->status(ElementRoundStatus::WAITING)->create();
        ElementSuggester::fake(fn() => ['suggestions' => collect(range(1, 8))->map(fn(int $i) => ['name' => "Item {$i}", 'description' => 'd', 'photo' => null])->all()]);

        (new GenerateElementSuggestions($people))->handle(app(StartElementRound::class));

        Queue::assertPushed(RenderElementSuggestion::class, 8);
        Queue::assertPushed(GenerateElementSuggestions::class, 1);
        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->is($places));
        expect($places->fresh()->status)->toBe(ElementRoundStatus::SUGGESTING)
            ->and($objects->fresh()->status)->toBe(ElementRoundStatus::WAITING);

        (new GenerateElementSuggestions($places->fresh()))->failed(new RuntimeException('down'));

        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->is($objects));
    });

    it('writes a waiting round right away once the chat reaches it', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ElementRound::factory()->for($this->project)->prepared()->status(ElementRoundStatus::SUGGESTING)->create();
        $places = ElementRound::factory()->for($this->project)->prepared()->type(ElementType::PLACE)->status(ElementRoundStatus::WAITING)->create();
        ProjectIntake::fake([[
            'reply' => 'Here are the places.', 'ask' => 'elements', 'done' => false, 'skip' => 'person',
            'element_round' => ['type' => 'place', 'brief' => 'The gate.', 'use_prepared' => true],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Skip people, places look right'])
            ->assertJsonPath('elementRound.id', $places->sqid)
            ->assertJsonPath('elementRound.status', 'suggesting');

        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->is($places));
    });

    it('does not prepare a category twice', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ElementRound::factory()->for($this->project)->prepared()->create();
        ProjectIntake::fake([[
            'reply' => 'x', 'ask' => 'elements', 'done' => false, 'skip' => null, 'element_round' => null,
            'prepare' => [['type' => 'person', 'brief' => 'Others.']],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'x']);

        expect($this->project->elementRounds()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('shows the prepared round when the brief still holds', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        $prepared = ElementRound::factory()->for($this->project)->prepared()->create();
        ElementSuggestion::factory()->for($prepared, 'round')->create(['position' => 1, 'name' => 'Guard', 'status' => ElementSuggestionStatus::READY]);
        ProjectIntake::fake([[
            'reply' => 'Here are the people.', 'ask' => 'elements', 'done' => false, 'skip' => null,
            'element_round' => ['type' => 'person', 'brief' => 'Visitors and a guard.', 'use_prepared' => true],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'That is right'])
            ->assertJsonPath('elementRound.id', $prepared->sqid)
            ->assertJsonPath('elementRound.options.0.name', 'Guard');

        expect($prepared->fresh()->presented_at)->not->toBeNull()
            ->and($this->project->elementRounds()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('replaces the prepared round when the answer changed the brief', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        $prepared = ElementRound::factory()->for($this->project)->prepared()->create();
        ProjectIntake::fake([[
            'reply' => 'Engineers it is.', 'ask' => 'elements', 'done' => false, 'skip' => null,
            'element_round' => ['type' => 'person', 'brief' => 'Engineers on the slipway.', 'use_prepared' => false],
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'No, engineers'])
            ->assertJsonPath('elementRound.status', 'suggesting');

        $round = $this->project->elementRounds()->sole();

        expect(ElementRound::query()->find($prepared->id))->toBeNull()
            ->and($round->brief)->toBe('Engineers on the slipway.')
            ->and($round->presented_at)->not->toBeNull();
        Queue::assertPushed(GenerateElementSuggestions::class, fn(GenerateElementSuggestions $job) => $job->round->is($round));
    });

    it('adds an extra round for a settled category and waits for it before finishing', function () {
        Queue::fake([GenerateElementSuggestions::class, GenerateProjectCover::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        foreach (ElementType::cases() as $type) {
            ElementRound::factory()->for($this->project)->type($type)->status(ElementRoundStatus::SKIPPED)->create();
        }
        ProjectIntake::fake([
            ['reply' => 'A crane operator, coming up.', 'ask' => 'elements', 'done' => false, 'skip' => null, 'element_round' => ['type' => 'person', 'brief' => 'A crane operator.', 'use_prepared' => false]],
            ['reply' => 'Ready.', 'ask' => null, 'done' => true, 'skip' => null, 'element_round' => null],
            ['reply' => 'Fine, finishing.', 'ask' => null, 'done' => true, 'skip' => 'person', 'element_round' => null],
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Also a crane operator'])
            ->assertJsonPath('elementRound.type', 'person');

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Done?'])
            ->assertJsonPath('done', false);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Never mind'])
            ->assertJsonPath('done', true);

        expect($this->project->elementRounds()->where('brief', 'A crane operator.')->sole()->status)->toBe(ElementRoundStatus::SKIPPED);
    });

    it('discards prepared rounds that were never reached when setup finishes', function () {
        $conversationId = projectInElementStage($this->director, $this->project);
        foreach (ElementType::cases() as $type) {
            ElementRound::factory()->for($this->project)->type($type)->status(ElementRoundStatus::SKIPPED)->create();
        }
        $leftover = ElementRound::factory()->for($this->project)->prepared()->create();
        ProjectIntake::fake([['reply' => 'Ready.', 'ask' => null, 'done' => true, 'skip' => null, 'element_round' => null]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Nothing else'])
            ->assertJsonPath('done', true);

        expect(ElementRound::query()->find($leftover->id))->toBeNull();
    });
});

describe('optional stage and cover', function () {
    it('lets the director skip the whole cast and sets stage and finishes without a cover', function () {
        Queue::fake([GenerateProjectCover::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ProjectIntake::fake([[
            'reply' => 'Fine, the project is ready.', 'ask' => null, 'done' => true,
            'skip' => null, 'skip_elements' => true, 'element_round' => null,
        ]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Not now'])
            ->assertSuccessful()
            ->assertJsonPath('done', true);

        expect($this->project->fresh()->setup_completed_at)->not->toBeNull()
            ->and($this->project->elementRounds()->pluck('status')->unique()->all())->toBe([ElementRoundStatus::SKIPPED])
            ->and($this->project->elementRounds()->count())->toBe(3);

        Queue::assertNotPushed(GenerateProjectCover::class);
    });

    it('draws a cover when setup finishes with cast and sets picked', function () {
        Queue::fake([GenerateProjectCover::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        Element::query()->create(['project_id' => $this->project->id, 'type' => ElementType::PERSON, 'name' => 'Guard', 'description' => 'd']);
        foreach (ElementType::cases() as $type) {
            ElementRound::factory()->for($this->project)->type($type)->status(ElementRoundStatus::PICKED)->create();
        }
        ProjectIntake::fake([['reply' => 'Ready.', 'ask' => null, 'done' => true, 'skip' => null, 'skip_elements' => false, 'element_round' => null]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Thanks'])
            ->assertJsonPath('done', true)
            ->assertJsonPath('project.coverStatus', 'painting')
            ->assertJsonPath('project.coverUrl', route('public.projects.cover.view', $this->project));

        Queue::assertPushed(GenerateProjectCover::class, fn(GenerateProjectCover $job) => $job->project->is($this->project));

        actingAs($this->director, 'director')
            ->getJson(route('public.projects.cover.view', $this->project))
            ->assertSuccessful()
            ->assertExactJson(['status' => 'painting']);
    });

    it('forbids polling another director\'s cover', function () {
        actingAs(Director::factory()->create(), 'director')
            ->getJson(route('public.projects.cover.view', $this->project))
            ->assertForbidden();
    });

    it('marks the cover failed when drawing fails, so the chat stops waiting', function () {
        $this->project->forceFill(['cover_status' => CoverStatus::PAINTING])->save();

        (new GenerateProjectCover($this->project))->failed(new RuntimeException('model down'));

        expect($this->project->fresh()->cover_status)->toBe(CoverStatus::FAILED);
    });

    it('paints the cast in one picture with the place behind them and shows it on the project page', function () {
        Image::fake([pngBase64()]);
        $this->project->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(Project::STYLE_REFERENCES);
        $make = function (ElementType $type, string $name) {
            $element = Element::query()->create(['project_id' => $this->project->id, 'type' => $type, 'name' => $name, 'description' => "{$name} look"]);
            $element->addMedia(UploadedFile::fake()->image("{$name}.png"))->toMediaCollection(Element::REFERENCE);
        };
        $make(ElementType::PERSON, 'Guard');
        $make(ElementType::PERSON, 'Visitor');
        $make(ElementType::PLACE, 'Main gate');
        $make(ElementType::OBJECT, 'Barrier');
        Element::query()->create(['project_id' => $this->project->id, 'type' => ElementType::OBJECT, 'name' => 'Unrendered', 'description' => 'd']);

        (new GenerateProjectCover($this->project))->handle(app(ProjectCoverPainter::class));

        expect($this->project->fresh()->getFirstMedia(Project::COVER))->not->toBeNull()
            ->and($this->project->fresh()->cover_status)->toBe(CoverStatus::READY);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->attachments->count() === 5
            && $prompt->size === ProjectCoverPainter::ASPECT_RATIO
            && $prompt->contains('stretches Main gate as a wide landscape')
            && $prompt->contains('Never a row of people standing side by side')
            && $prompt->contains('Attached image 1: Guard (person)')
            && ! $prompt->contains('Unrendered'));

        $this->project->forceFill(['setup_completed_at' => now()])->save();

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $this->project))
            ->assertInertia(fn($page) => $page->where('project.coverUrl', fn(?string $url) => str_contains((string) $url, '/media/') && str_contains((string) $url, 'signature=')));
    });

    it('skips the cover when no element has a reference image', function () {
        Image::fake();
        Element::query()->create(['project_id' => $this->project->id, 'type' => ElementType::PERSON, 'name' => 'Guard', 'description' => 'd']);

        (new GenerateProjectCover($this->project))->handle(app(ProjectCoverPainter::class));

        Image::assertNothingGenerated();
        expect($this->project->fresh()->getFirstMedia(Project::COVER))->toBeNull()
            ->and($this->project->fresh()->cover_status)->toBeNull();
    });
});

describe('background work', function () {
    it('analyses an uploaded photo', function () {
        PhotoAnalyst::fake([['caption' => 'A guard at the main gate.', 'items' => [['type' => 'person', 'name' => 'Security guard', 'description' => 'Dark uniform.'], ['type' => 'object', 'name' => '', 'description' => 'nameless']]]]);
        $this->project->addMedia(UploadedFile::fake()->image('gate.jpg', 800, 600))->toMediaCollection(Project::CONTENT_REFERENCES);
        $photo = PhotoInventory::photos($this->project)->first();

        (new AnalyzePhoto($photo))->handle();

        expect($photo->fresh()->getCustomProperty(PhotoInventory::PROPERTY))->toEqual([['type' => 'person', 'name' => 'Security guard', 'description' => 'Dark uniform.']]);
        expect($photo->fresh()->getCustomProperty(Project::CAPTION))->toBe('A guard at the main gate.');

        PhotoAnalyst::assertPrompted(fn(AgentPrompt $prompt) => $prompt->attachments->count() === 1);

        (new AnalyzePhoto($photo))->failed(new RuntimeException('x'));
        expect($photo->fresh()->getCustomProperty(PhotoInventory::PROPERTY))->toBe([]);
    });

    it('writes the suggestions, links photos and queues a render each', function () {
        Queue::fake([RenderElementSuggestion::class]);
        $this->project->addMedia(UploadedFile::fake()->image('gate.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);
        $round = ElementRound::factory()->for($this->project)->status(ElementRoundStatus::SUGGESTING)->create();
        ElementSuggester::fake([['suggestions' => collect(range(1, 8))->map(fn(int $i) => ['name' => "Person {$i}", 'description' => "Look {$i}", 'photo' => $i === 1 ? 1 : ($i === 2 ? 9 : null)])->all()]]);

        (new GenerateElementSuggestions($round))->handle(app(StartElementRound::class));

        $suggestions = $round->suggestions()->get();

        expect($round->fresh()->status)->toBe(ElementRoundStatus::READY)
            ->and($suggestions)->toHaveCount(8)
            ->and($suggestions[0]->source_media_id)->toBe(PhotoInventory::photos($this->project)->first()->id)
            ->and($suggestions[1]->source_media_id)->toBeNull()
            ->and($suggestions[7]->name)->toBe('Person 8');

        Queue::assertPushed(RenderElementSuggestion::class, 8);
        ElementSuggester::assertPrompted(fn(AgentPrompt $prompt) => str_contains($prompt->prompt, 'Visitors, contractors and the security guard'));

        (new GenerateElementSuggestions($round))->failed(new RuntimeException('x'));
        expect($round->fresh()->status)->toBe(ElementRoundStatus::FAILED);
    });

    it('renders a suggestion, restyling its source photo', function () {
        Image::fake([pngBase64()]);
        $this->project->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(Project::STYLE_REFERENCES);
        $this->project->addMedia(UploadedFile::fake()->image('tug.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);
        $round = ElementRound::factory()->for($this->project)->type(ElementType::OBJECT)->create();
        $suggestion = ElementSuggestion::factory()->for($round, 'round')->create(['name' => 'Stan Tug', 'source_media_id' => PhotoInventory::photos($this->project)->first()->id]);

        (new RenderElementSuggestion($suggestion))->handle(app(ElementPainter::class));

        expect($suggestion->fresh()->status)->toBe(ElementSuggestionStatus::READY)
            ->and($suggestion->fresh()->render())->not->toBeNull();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->attachments->count() === 2
            && $prompt->contains('photo of the real Stan Tug')
            && $prompt->contains('Show only this object'));
    });
});

describe('picking', function () {
    it('turns picked suggestions into elements and deletes the rest', function () {
        $round = ElementRound::factory()->for($this->project)->create();
        $picked = ElementSuggestion::factory()->for($round, 'round')->ready()->create(['name' => 'Guard', 'position' => 1]);
        $picked->addMedia(UploadedFile::fake()->image('guard.png'))->toMediaCollection(ElementSuggestion::RENDER);
        $other = ElementSuggestion::factory()->for($round, 'round')->ready()->create(['name' => 'Visitor', 'position' => 2]);
        $other->addMedia(UploadedFile::fake()->image('visitor.png'))->toMediaCollection(ElementSuggestion::RENDER);
        $otherFile = $other->render()->getPathRelativeToRoot();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.elements.rounds.pick', [$this->project, $round]), ['suggestions' => [$picked->sqid]])
            ->assertSuccessful()
            ->assertJsonPath('names', ['Guard'])
            ->assertJsonPath('round.status', 'picked')
            ->assertJsonCount(1, 'round.options')
            ->assertJsonPath('round.options.0.picked', true);

        $element = $this->project->elements()->firstOrFail();

        expect($element->name)->toBe('Guard')
            ->and($element->type)->toBe(ElementType::PERSON)
            ->and($element->reference())->not->toBeNull()
            ->and(ElementSuggestion::query()->whereKey($other->id)->exists())->toBeFalse();

        Storage::disk(Disk::TENANT->value)->assertMissing($otherFile);
    });

    it('refuses suggestions that are still rendering and rounds that are settled', function () {
        $round = ElementRound::factory()->for($this->project)->create();
        $pending = ElementSuggestion::factory()->for($round, 'round')->create();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.elements.rounds.pick', [$this->project, $round]), ['suggestions' => [$pending->sqid]])
            ->assertUnprocessable();

        $round->update(['status' => ElementRoundStatus::PICKED]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.elements.rounds.pick', [$this->project, $round]), ['suggestions' => [$pending->sqid]])
            ->assertUnprocessable();

        expect($this->project->elements()->count())->toBe(0);
    });

    it('reports a round while it fills and keeps other directors out', function () {
        $round = ElementRound::factory()->for($this->project)->create();
        ElementSuggestion::factory()->for($round, 'round')->create();

        actingAs($this->director, 'director')
            ->getJson(route('public.projects.elements.rounds.view', [$this->project, $round]))
            ->assertSuccessful()
            ->assertJsonPath('options.0.status', 'pending')
            ->assertJsonPath('options.0.thumbnailUrl', null);

        actingAs(Director::factory()->create(), 'director')
            ->getJson(route('public.projects.elements.rounds.view', [$this->project, $round]))
            ->assertForbidden();

        actingAs(Director::factory()->create(), 'director')
            ->postJson(route('public.projects.elements.rounds.pick', [$this->project, $round]), ['suggestions' => ['x']])
            ->assertForbidden();
    });
});

it('deletes rounds, suggestions and their renders with the project', function () {
    $round = ElementRound::factory()->for($this->project)->create();
    $suggestion = ElementSuggestion::factory()->for($round, 'round')->ready()->create();
    $suggestion->addMedia(UploadedFile::fake()->image('s.png'))->toMediaCollection(ElementSuggestion::RENDER);

    $this->project->delete();

    expect(ElementRound::query()->count())->toBe(0)
        ->and(ElementSuggestion::query()->count())->toBe(0)
        ->and(Storage::disk(Disk::TENANT->value)->allFiles())->toBe([]);
});
