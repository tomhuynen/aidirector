<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\TextRules;
use App\Enums\Disk;
use App\Models\Director;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Storage::fake(config('uploads.disk'));
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function brandingPng(): string
{
    $image = imagecreatetruecolor(20, 10);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function withLogo(Project $project, string $brand): Project
{
    $project->addMediaFromString(brandingPng())->usingName($brand)->usingFileName("{$brand}.png")->toMediaCollection(Project::LOGOS);

    return $project->fresh();
}

it('adds uploaded logos to the branding, named by their file, and shows them on the project page', function () {
    $upload = Upload::factory()->named('Damen.png')->create();
    Storage::disk(config('uploads.disk'))->put($upload->path, brandingPng());

    actingAs($this->director, 'director')
        ->post(route('public.projects.branding.store', $this->project), ['logos' => [$upload->sqid]])
        ->assertSessionHasNoErrors();

    expect($this->project->getMedia(Project::LOGOS)->pluck('name')->all())->toBe(['Damen'])
        ->and($this->project->brandNames())->toBe(['Damen']);

    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertInertia(fn($page) => $page->where('branding.logos.0.name', 'Damen')->has('branding.logos.0.imageUrl'));
});

it('removes a logo, but only one of this project\'s branding', function () {
    $logo = withLogo($this->project, 'Damen')->getFirstMedia(Project::LOGOS);
    $otherLogo = withLogo(Project::factory()->create(), 'Other')->getFirstMedia(Project::LOGOS);

    actingAs($this->director, 'director')->delete(route('public.projects.branding.destroy', [$this->project, $otherLogo]))->assertNotFound();
    actingAs($this->director, 'director')->delete(route('public.projects.branding.destroy', [$this->project, $logo]))->assertRedirect();

    expect($this->project->fresh()->getMedia(Project::LOGOS))->toBeEmpty();
});

it('keeps other directors out of the branding', function () {
    $upload = Upload::factory()->create();

    actingAs(Director::factory()->create(), 'director')
        ->post(route('public.projects.branding.store', $this->project), ['logos' => [$upload->sqid]])
        ->assertForbidden();
});

it('gives an image the logos its text names, or every logo when it only says logo', function () {
    $project = withLogo(withLogo($this->project, 'Damen'), 'Navy');

    expect($project->logosFor('A host in a blue vest with the Damen logo on the back')->pluck('name')->all())->toBe(['Damen'])
        ->and($project->logosFor('A van with the company logo on its side')->pluck('name')->all())->toBe(['Damen', 'Navy'])
        ->and($project->logosFor('A plain blue vest'))->toBeEmpty()
        ->and($this->project->fresh()->logosFor(''))->toBeEmpty();
});

it('draws an element that carries the brand from the uploaded logo, and allows that text only', function () {
    Image::fake(fn() => base64_encode(brandingPng()));
    withLogo($this->project, 'Damen');
    $host = Element::factory()->for($this->project)->create(['type' => 'person', 'name' => 'Damen host', 'description' => 'A man in a navy jacket with the Damen logo on the chest.']);
    $plain = Element::factory()->for($this->project)->create(['type' => 'object', 'name' => 'Visitor badge', 'description' => 'A clear plastic badge.']);

    app(App\Ai\ElementPainter::class)->paint($host);
    app(App\Ai\ElementPainter::class)->paint($plain);

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Damen host')
        && $prompt->contains('attached image is the Damen logo.')
        && $prompt->contains(TextRules::withLogos(['Damen']))
        && ! $prompt->contains(TextRules::NO_TEXT));
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Visitor badge')
        && $prompt->contains(TextRules::NO_TEXT)
        && ! $prompt->contains('logo.'));
});

it('lets the planners ask only for a logo the branding has', function () {
    $shot = Shot::factory()->for($this->project)->create();

    expect((string) (new StorylineWriter($shot))->instructions())->toContain('The project has no logo, so nothing carries one.');

    withLogo($this->project, 'Damen');

    expect((string) (new StorylineWriter($shot->fresh()))->instructions())
        ->toContain('The only exception is the branding: the logos of Damen')
        ->not->toContain("the company's own logo or name");
});

it('puts a picked logo on an element as a change that names the brand', function () {
    Illuminate\Support\Facades\Queue::fake();
    $logo = withLogo($this->project, 'Damen')->getFirstMedia(Project::LOGOS);
    $helmet = Element::factory()->for($this->project)->create(['type' => 'object', 'name' => 'Helmet', 'description' => 'A blue helmet.']);
    $helmet->addMediaFromString(brandingPng())->usingFileName('helmet.png')->toMediaCollection(Element::REFERENCE);

    actingAs($this->director, 'director')
        ->post(route('public.projects.elements.update', [$this->project, $helmet]), ['name' => 'Helmet', 'description' => 'A blue helmet.', 'logos' => [$logo->id]])
        ->assertSessionHasNoErrors();

    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\UpdateElementImage::class, fn($job) => $job->instruction === 'Add the Damen logo to it.');
    expect($this->project->fresh()->logosFor('Add the Damen logo to it.')->first()->id)->toBe($logo->id);
});

it('refuses a logo that is not in the branding, and asking for a logo before there is one', function () {
    $helmet = Element::factory()->for($this->project)->create(['type' => 'object', 'name' => 'Helmet', 'description' => 'A blue helmet.']);
    $otherLogo = withLogo(Project::factory()->create(), 'Other')->getFirstMedia(Project::LOGOS);

    actingAs($this->director, 'director')
        ->post(route('public.projects.elements.update', [$this->project, $helmet]), ['name' => 'Helmet', 'description' => 'A blue helmet.', 'change' => 'add the logo on the front'])
        ->assertSessionHasErrors(['change' => 'There is no logo in the branding yet. Upload one on the project page first.']);

    actingAs($this->director, 'director')
        ->post(route('public.projects.elements.update', [$this->project, $helmet]), ['name' => 'Helmet', 'description' => 'A blue helmet.', 'logos' => [$otherLogo->id]])
        ->assertSessionHasErrors('logos');
});
