<?php

declare(strict_types=1);

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use App\Models\Project;
use Database\Seeders\Tenant\DamenProjectSeeder;

it('seeds the Damen project for the first director without any shots', function () {
    $director = Director::factory()->create();
    Director::factory()->create();

    $this->seed(DamenProjectSeeder::class);

    $project = Project::query()->where('title', 'Damen')->sole();

    expect($project->director_id)->toBe($director->id)
        ->and($project->purpose)->toBe(ProjectPurpose::E_LEARNING)
        ->and($project->aspect_ratio)->toBe(AspectRatio::PORTRAIT)
        ->and($project->description)->toContain('Dutch shipbuilder')
        ->and($project->style['look'])->not->toBeEmpty()
        ->and($project->shots()->count())->toBe(0);
});

it('creates a demo director when none exists', function () {
    $this->seed(DamenProjectSeeder::class);

    $project = Project::query()->where('title', 'Damen')->sole();

    expect($project->director->email)->toBe('director@example.com');
});

it('does not duplicate the project when seeded twice', function () {
    Director::factory()->create();

    $this->seed(DamenProjectSeeder::class);
    $this->seed(DamenProjectSeeder::class);

    expect(Project::query()->where('title', 'Damen')->count())->toBe(1);
});
