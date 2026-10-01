<?php

declare(strict_types=1);

use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
});

function pngBytes(): string
{
    $image = imagecreatetruecolor(16, 9);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

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

    it('shows the pinned style sheet on the project card', function () {
        Storage::fake(Disk::TENANT->value);

        $styled = Project::factory()->ownedBy($this->director)->create(['updated_at' => now()->addMinute()]);
        Project::factory()->ownedBy($this->director)->create();

        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);

        $styled->addMediaFromString((string) ob_get_clean())
            ->usingFileName('style-sheet.png')
            ->toMediaCollection(Project::STYLE_REFERENCES);

        $response = actingAs($this->director, 'director')
            ->get(route('public.projects.index'))
            ->assertInertia(fn($page) => $page
                ->where('projects.0.styleReferenceUrl', fn(string $url) => str_contains($url, '/media/') && str_contains($url, 'signature='))
                ->where('projects.1.styleReferenceUrl', null));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['projects'][0]['styleReferenceUrl'])
            ->assertSuccessful();
    });
});

describe('create and update', function () {
    it('shows the intake chat instead of a form for new projects', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.create'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('projects/create')->has('greeting')->has('chatUrl'));
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
    it('shows the project overview with its style and shots', function () {
        Storage::fake(Disk::TENANT->value);

        $project = Project::factory()->ownedBy($this->director)->create(['website' => 'https://damen.com', 'video_resolution' => '480p']);
        $shots = Shot::factory()->for($project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();
        $project->addMediaFromString(pngBytes())->usingFileName('sheet.png')->toMediaCollection(Project::STYLE_REFERENCES);

        $response = actingAs($this->director, 'director')
            ->get(route('public.projects.view', $project))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->component('projects/view')
                ->where('project.id', $project->sqid)
                ->where('project.website', 'https://damen.com')
                ->where('project.videoResolution', '480p')
                ->where('project.styleReferenceUrl', fn(string $url) => str_contains($url, 'signature='))
                ->where('project.links.editor', route('public.shots.view', [$project, $shots->first()]))
                ->missing('references')
                ->has('shots', 2)
                ->where('shots.1.url', route('public.shots.view', [$project, $shots->last()])));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['project']['styleReferenceUrl'])
            ->assertSuccessful();
    });

    it('points the editor link at a new shot when there are none', function () {
        $project = Project::factory()->ownedBy($this->director)->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $project))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->component('projects/view')
                ->has('shots', 0)
                ->where('project.links.editor', route('public.shots.create', $project)));
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
