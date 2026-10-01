<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Enums\Disk;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use App\Models\StyleOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    ProjectIntake::fake([
        ['reply' => 'What is it about?', 'description' => null, 'purpose' => null, 'title' => null, 'ask' => null, 'done' => false],
        ['reply' => 'Any photos?', 'description' => 'd', 'purpose' => 'e-learning', 'title' => 'Damen', 'ask' => 'photos', 'done' => false],
        ['reply' => 'Here are some directions.', 'description' => 'd', 'purpose' => 'e-learning', 'title' => 'Damen', 'ask' => 'style', 'done' => false],
    ]);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();

    $agent = (new ProjectIntake())->forUser($this->director);
    $this->conversationId = $agent->prompt('Damen', provider: 'openrouter', model: 'test')->conversationId;

    $this->travel(1)->seconds();
    $this->project->addMedia(UploadedFile::fake()->image('tug.jpg', 640, 480))->toMediaCollection(Project::CONTENT_REFERENCES);
    (new ProjectIntake())->continue($this->conversationId, as: $this->director)
        ->prompt("Here you go\n\nThe director added 1 photo:\n1. A grey tug.", provider: 'openrouter', model: 'test');

    $this->project->update(['conversation_id' => $this->conversationId]);
});

it('sends a project without a style back to its chat', function () {
    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertRedirect(route('public.projects.setup', $this->project));
});

it('opens the editor once a style is pinned', function () {
    $this->project->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(Project::STYLE_REFERENCES);
    $shot = Shot::factory()->for($this->project)->create();

    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

    actingAs($this->director, 'director')
        ->get(route('public.projects.setup', $this->project))
        ->assertRedirect(route('public.projects.view', $this->project));
});

it('leaves projects that did not start in the chat alone', function () {
    $project = Project::factory()->ownedBy($this->director)->create();

    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $project))
        ->assertSuccessful()
        ->assertInertia(fn($page) => $page->component('projects/view'));
});

it('resumes the thread where it was left', function () {
    $this->travel(1)->seconds();
    (new ProjectIntake())->continue($this->conversationId, as: $this->director)
        ->prompt('No more photos', provider: 'openrouter', model: 'test');

    $this->travel(1)->seconds();
    $option = StyleOption::factory()->for($this->project)->ready()->create(['round' => 1, 'position' => 1]);
    StyleOption::factory()->for($this->project)->create(['round' => 1, 'position' => 2]);

    actingAs($this->director, 'director')
        ->get(route('public.projects.setup', $this->project))
        ->assertSuccessful()
        ->assertInertia(fn($page) => $page
            ->component('projects/create')
            ->where('resume.conversation', $this->conversationId)
            ->where('resume.ask', 'style')
            ->where('resume.project.id', $this->project->sqid)
            ->where('resume.project.styleRoundsUrl', route('public.projects.style.round', $this->project))
            ->has('resume.messages', 8)
            ->where('resume.messages.0.content', ProjectIntake::greeting())
            ->where('resume.messages.1.role', 'user')
            ->where('resume.messages.1.content', 'Damen')
            ->where('resume.messages.2.content', 'What is it about?')
            ->where('resume.messages.3.content', 'Here you go')
            ->has('resume.messages.3.attachments', 1)
            ->where('resume.messages.3.attachments.0.name', 'tug.jpg')
            ->where('resume.messages.4.content', 'Any photos?')
            ->where('resume.messages.5.content', 'No more photos')
            ->has('resume.messages.5.attachments', 0)
            ->where('resume.messages.6.content', 'Here are some directions.')
            ->where('resume.messages.7.kind', 'style-options')
            ->where('resume.messages.7.round', 1)
            ->where('resume.messages.7.optionsUrl', route('public.projects.style.options', [$this->project, 1]))
            ->where('resume.messages.7.options.0.id', $option->sqid)
            ->where('resume.messages.7.options.1.status', 'pending'));
});

it('forbids resuming another director\'s project', function () {
    actingAs(Director::factory()->create(), 'director')
        ->get(route('public.projects.setup', $this->project))
        ->assertForbidden();
});
