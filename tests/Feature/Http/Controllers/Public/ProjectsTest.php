<?php

declare(strict_types=1);

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use App\Models\Project;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
});

function validProject(array $overrides = []): array
{
    return [
        'title' => 'Mailbox explainer',
        'purpose' => ProjectPurpose::EXPLAINER->value,
        'description' => 'How to post a letter.',
        'aspectRatio' => AspectRatio::LANDSCAPE->value,
        'defaultDuration' => 5,
        'style' => [
            'look' => 'Clean 3D cartoon',
            'palette' => 'Navy and red',
            'medium' => '3D illustration',
            'mood' => 'Friendly',
            'references' => [],
        ],
        ...$overrides,
    ];
}

describe('index', function () {
    it('lists only the projects of the current user', function () {
        Project::factory()->ownedBy($this->director)->count(2)->create();
        Project::factory()->ownedBy($this->director)->archived()->create();
        Project::factory()->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.index'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('projects/index')->has('projects', 2));
    });
});

describe('create and update', function () {
    it('shows the create form', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.create'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('projects/update')->has('purposes', 6)->has('aspectRatios', 3));
    });

    it('creates a project owned by the current user', function () {
        $response = actingAs($this->director, 'director')->post(route('public.projects.store'), validProject());

        $project = Project::query()->firstOrFail();

        $response->assertRedirect(route('public.projects.view', $project));

        expect($project->director_id)->toBe($this->director->id)
            ->and($project->purpose)->toBe(ProjectPurpose::EXPLAINER)
            ->and($project->aspect_ratio)->toBe(AspectRatio::LANDSCAPE)
            ->and($project->style['look'])->toBe('Clean 3D cartoon');
    });

    it('validates the input', function (array $overrides, string $field) {
        actingAs($this->director, 'director')
            ->post(route('public.projects.store'), validProject($overrides))
            ->assertSessionHasErrors($field);
    })->with([
        'missing title' => [['title' => ''], 'title'],
        'unknown purpose' => [['purpose' => 'poetry'], 'purpose'],
        'unknown aspect ratio' => [['aspectRatio' => '4:3'], 'aspectRatio'],
        'duration too long' => [['defaultDuration' => 90], 'defaultDuration'],
    ]);

    it('updates an owned project', function () {
        $project = Project::factory()->ownedBy($this->director)->create();

        actingAs($this->director, 'director')
            ->post(route('public.projects.update', $project), validProject(['title' => 'Renamed']))
            ->assertRedirect(route('public.projects.view', $project));

        expect($project->fresh()->title)->toBe('Renamed');
    });

    it('forbids editing another director\'s project', function () {
        $project = Project::factory()->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.update', $project))
            ->assertForbidden();

        actingAs($this->director, 'director')
            ->post(route('public.projects.update', $project), validProject())
            ->assertForbidden();
    });
});

describe('view and destroy', function () {
    it('shows an owned project with its shots', function () {
        $project = Project::factory()->ownedBy($this->director)->hasShots(3)->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $project))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('projects/view')->has('shots', 3));
    });

    it('forbids viewing another director\'s project', function () {
        $project = Project::factory()->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $project))
            ->assertForbidden();
    });

    it('destroys an owned project', function () {
        $project = Project::factory()->ownedBy($this->director)->create();

        actingAs($this->director, 'director')
            ->delete(route('public.projects.destroy', $project))
            ->assertRedirect(route('public.projects.index'));

        $this->assertModelMissing($project);
    });
});
