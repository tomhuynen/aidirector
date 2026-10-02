<?php

declare(strict_types=1);

use App\Enums\AspectRatio;
use App\Enums\Disk;
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

describe('format', function () {
    it('offers the aspect ratios and resolutions with their frame sizes', function () {
        $project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT, 'video_resolution' => '480p']);

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $project))
            ->assertInertia(fn($page) => $page
                ->where('videoFormats.aspectRatios', [
                    ['value' => '16:9', 'name' => 'Landscape'],
                    ['value' => '9:16', 'name' => 'Portrait'],
                    ['value' => '1:1', 'name' => 'Square'],
                ])
                ->where('videoFormats.resolutions', ['480p', '720p', '1080p'])
                ->where('videoFormats.sizes.16:9 480p', ['width' => 854, 'height' => 480])
                ->where('videoFormats.sizes.9:16 1080p', ['width' => 1080, 'height' => 1920])
                ->where('videoFormats.sizes.1:1 720p', ['width' => 720, 'height' => 720])
                ->missing('videoFormats.sizes.1:1 4K')
                ->where('project.aspectRatio', '9:16')
                ->where('project.videoResolution', '480p')
                ->where('project.links.format', route('public.projects.format', $project)));
    });

    it('sets the project aspect ratio and resolution', function () {
        $project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT]);

        actingAs($this->director, 'director')
            ->post(route('public.projects.format', $project), ['aspectRatio' => '16:9', 'resolution' => '1080p'])
            ->assertRedirect(route('public.projects.view', $project));

        expect($project->fresh())
            ->aspect_ratio->toBe(AspectRatio::LANDSCAPE)
            ->videoResolution()->toBe('1080p');
    });

    it('only accepts formats the project and the video model support', function (array $format, string $field) {
        $project = Project::factory()->ownedBy($this->director)->create();

        actingAs($this->director, 'director')
            ->post(route('public.projects.format', $project), $format)
            ->assertSessionHasErrors($field);
    })->with([
        'unknown ratio' => [['aspectRatio' => '21:9', 'resolution' => '720p'], 'aspectRatio'],
        'unknown resolution' => [['aspectRatio' => '16:9', 'resolution' => '8K'], 'resolution'],
    ]);

    it('forbids changing another director\'s format', function () {
        $project = Project::factory()->create();

        actingAs($this->director, 'director')
            ->post(route('public.projects.format', $project), ['aspectRatio' => '16:9', 'resolution' => '720p'])
            ->assertForbidden();
    });
});

describe('create', function () {
    it('shows the intake chat instead of a form for new projects', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.create'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('projects/create')->has('greeting')->has('chatUrl'));
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

it('makes a new project portrait unless told otherwise', function () {
    expect((new Project())->aspect_ratio)->toBe(AspectRatio::PORTRAIT);
});
