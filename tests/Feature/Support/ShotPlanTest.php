<?php

declare(strict_types=1);

use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\ShotPlan;

it('adds the people a keyframe names but leaves out of its cast, so none is made up', function () {
    $project = Project::factory()->create();
    Element::factory()->for($project)->create(['name' => 'Damen host', 'type' => ElementType::PERSON]);
    Element::factory()->for($project)->create(['name' => 'Helmeted visitor', 'type' => ElementType::PERSON]);
    Element::factory()->for($project)->create(['name' => 'helmet', 'type' => ElementType::OBJECT]);
    $shot = Shot::factory()->for($project)->create()->load('project');

    $keyframes = ShotPlan::withNamedPeople($shot, [
        ['description' => 'The Helmeted visitor waits without a helmet. The Damen host stands between them.', 'elements' => ['Gate', 'helmeted visitor']],
        ['description' => 'The Helmeted visitor waits alone.', 'elements' => ['Helmeted visitor']],
        ['description' => 'The Damen hostess waves.', 'elements' => []],
    ]);

    expect(array_column($keyframes, 'elements'))->toBe([
        ['Gate', 'helmeted visitor', 'Damen host'],
        ['Helmeted visitor'],
        [],
    ]);
});

it('puts the named people into the plan agreed in the chat', function () {
    $project = Project::factory()->create();
    Element::factory()->for($project)->create(['name' => 'Damen host', 'type' => ElementType::PERSON]);
    $shot = Shot::factory()->for($project)->create()->load('project');

    ShotPlan::apply($shot, ['kind' => 'scene', 'storyline' => 'The host waits.', 'seconds' => 5, 'keyframes' => [
        ['title' => 'Waiting', 'description' => 'The Damen host waits at the gate.', 'elements' => []],
    ]]);

    expect($shot->fresh()->storylineKeyframes()[0]['elements'])->toBe(['Damen host']);
});
