<?php

declare(strict_types=1);

use App\Ai\Agents\ElementSuggester;
use App\Ai\Agents\PhotoAnalyst;
use App\Ai\Agents\ProjectIntake;
use App\Ai\ElementPainter;
use App\Enums\Disk;
use App\Enums\ElementRoundStatus;
use App\Enums\ElementSuggestionStatus;
use App\Enums\ElementType;
use App\Jobs\AnalyzePhoto;
use App\Jobs\GenerateElementSuggestions;
use App\Jobs\RenderElementSuggestion;
use App\Models\Director;
use App\Models\Element;
use App\Models\ElementRound;
use App\Models\ElementSuggestion;
use App\Models\Project;
use App\Support\Elements\PhotoInventory;
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

    it('does not start rounds before a style is pinned or for a settled category', function () {
        Queue::fake([GenerateElementSuggestions::class]);
        $conversationId = projectInElementStage($this->director, $this->project);
        ElementRound::factory()->for($this->project)->status(ElementRoundStatus::PICKED)->create();
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

describe('background work', function () {
    it('analyses an uploaded photo', function () {
        PhotoAnalyst::fake([['items' => [['type' => 'person', 'name' => 'Security guard', 'description' => 'Dark uniform.'], ['type' => 'object', 'name' => '', 'description' => 'nameless']]]]);
        $this->project->addMedia(UploadedFile::fake()->image('gate.jpg', 800, 600))->toMediaCollection(Project::CONTENT_REFERENCES);
        $photo = PhotoInventory::photos($this->project)->first();

        (new AnalyzePhoto($photo))->handle();

        expect($photo->fresh()->getCustomProperty(PhotoInventory::PROPERTY))->toEqual([['type' => 'person', 'name' => 'Security guard', 'description' => 'Dark uniform.']]);

        PhotoAnalyst::assertPrompted(fn(AgentPrompt $prompt) => $prompt->attachments->count() === 1);

        (new AnalyzePhoto($photo))->failed(new RuntimeException('x'));
        expect($photo->fresh()->getCustomProperty(PhotoInventory::PROPERTY))->toBe([]);
    });

    it('writes the suggestions, links photos and queues a render each', function () {
        Queue::fake([RenderElementSuggestion::class]);
        $this->project->addMedia(UploadedFile::fake()->image('gate.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);
        $round = ElementRound::factory()->for($this->project)->status(ElementRoundStatus::SUGGESTING)->create();
        ElementSuggester::fake([['suggestions' => collect(range(1, 12))->map(fn(int $i) => ['name' => "Person {$i}", 'description' => "Look {$i}", 'photo' => $i === 1 ? 1 : ($i === 2 ? 9 : null)])->all()]]);

        (new GenerateElementSuggestions($round))->handle();

        $suggestions = $round->suggestions()->get();

        expect($round->fresh()->status)->toBe(ElementRoundStatus::READY)
            ->and($suggestions)->toHaveCount(12)
            ->and($suggestions[0]->source_media_id)->toBe(PhotoInventory::photos($this->project)->first()->id)
            ->and($suggestions[1]->source_media_id)->toBeNull()
            ->and($suggestions[11]->name)->toBe('Person 12');

        Queue::assertPushed(RenderElementSuggestion::class, 12);
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
