<?php

declare(strict_types=1);

use App\Models\Director;
use App\Models\Project;
use App\Support\Projects\ProjectSettings;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

it('starts with the voice-over off and no languages', function () {
    expect($this->project->fresh()->settings)
        ->toBeInstanceOf(ProjectSettings::class)
        ->voiceOver->toBeFalse()
        ->enabledLocales()->toBe([]);
});

it('turns the voice-over on with its languages, kept in the catalogue order', function () {
    actingAs($this->director, 'director')
        ->post(route('public.projects.voice-over', $this->project), ['enabled' => true, 'locales' => ['en-GB', 'nl-NL']])
        ->assertSessionHasNoErrors();

    $settings = $this->project->fresh()->settings;

    expect($settings->voiceOver)->toBeTrue()
        ->and($settings->enabledLocales())->toBe(['nl-NL', 'en-GB']);
});

it('keeps the languages but enables none while the voice-over is off', function () {
    actingAs($this->director, 'director')
        ->post(route('public.projects.voice-over', $this->project), ['enabled' => false, 'locales' => ['nl-NL']]);

    $settings = $this->project->fresh()->settings;

    expect($settings->voiceOverLocales)->toBe(['nl-NL'])
        ->and($settings->enabledLocales())->toBe([]);
});

it('rejects languages it cannot make a voice-over in', function () {
    actingAs($this->director, 'director')
        ->post(route('public.projects.voice-over', $this->project), ['enabled' => true, 'locales' => ['xx-XX']])
        ->assertSessionHasErrors('locales.0');
});

it('drops unknown keys and languages when reading stored settings', function () {
    $settings = ProjectSettings::fromArray(['voice_over' => ['enabled' => true, 'locales' => ['nl-NL', 'xx-XX']], 'other' => 1]);

    expect($settings->toArray())->toBe(['voice_over' => ['enabled' => true, 'locales' => ['nl-NL']]]);
});

it('shows the languages by name on the project page', function () {
    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertInertia(fn($page) => $page
            ->where('project.voiceOver', false)
            ->where('voiceOverLanguages.0', ['code' => 'nl-NL', 'name' => 'Dutch (Netherlands)'])
            ->where('project.links.voiceOver', route('public.projects.voice-over', $this->project)));
});

it('forbids changing another director\'s voice-over', function () {
    $other = Project::factory()->create();

    actingAs($this->director, 'director')
        ->post(route('public.projects.voice-over', $other), ['enabled' => true, 'locales' => []])
        ->assertForbidden();
});
