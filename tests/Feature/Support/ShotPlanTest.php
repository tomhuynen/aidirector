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

it('drops a person from a keyframe\'s cast when its description never names them', function () {
    $project = Project::factory()->create();
    Element::factory()->for($project)->create(['name' => 'Helmeted visitor', 'type' => ElementType::PERSON]);
    Element::factory()->for($project)->create(['name' => 'Reception security', 'type' => ElementType::PERSON]);
    Element::factory()->for($project)->create(['name' => 'Reception Security Desk', 'type' => ElementType::PLACE]);
    $shot = Shot::factory()->for($project)->create()->load('project');

    $keyframes = ShotPlan::withNamedPeople($shot, [
        ['description' => 'At the wall beside the Reception Security Desk, the Helmeted visitor holds the handset.', 'elements' => ['Helmeted visitor', 'Reception Security Desk', 'Reception security']],
        ['description' => 'Reception security nods to the Helmeted visitor.', 'elements' => ['Helmeted visitor', 'Reception security']],
        ['description' => 'She hangs up.', 'elements' => ['Helmeted visitor']],
    ]);

    expect(array_column($keyframes, 'elements'))->toBe([
        ['Helmeted visitor', 'Reception Security Desk'],
        ['Helmeted visitor', 'Reception security'],
        ['Helmeted visitor'],
    ]);
});

it('lists what a keyframe handles among its elements, since only those are cut out of the drawing and can be moved', function () {
    $project = Project::factory()->create();
    Element::factory()->for($project)->create(['name' => 'Site helicopter', 'type' => ElementType::OBJECT]);
    $shot = Shot::factory()->for($project)->create()->load('project');

    $keyframes = ShotPlan::withNamedPeople($shot, [
        ['description' => 'The helicopter flies in over the sea.', 'elements' => ['Flight deck'], 'handled' => ['site helicopter']],
        ['description' => 'It lands.', 'elements' => ['Flight deck', 'Site helicopter'], 'handled' => ['Site helicopter', 'Unknown crate']],
    ]);

    expect(array_column($keyframes, 'elements'))->toBe([
        ['Flight deck', 'Site helicopter'],
        ['Flight deck', 'Site helicopter'],
    ]);
});

it('makes the storyline the beats of the keyframes, so each sentence belongs to one keyframe', function () {
    $shot = Shot::factory()->for(Project::factory())->create()->load('project');

    ShotPlan::apply($shot, ['kind' => 'scene', 'storyline' => 'Something else entirely.', 'seconds' => 5, 'keyframes' => [
        ['title' => 'Reach', 'beat' => 'She reaches for the handset.', 'description' => 'She stands by the wall phone.', 'elements' => []],
        ['title' => 'Call', 'beat' => 'She calls reception.', 'description' => 'She holds the handset to her ear.', 'elements' => []],
    ]]);

    expect($shot->fresh()->chosen_storyline['storyline'])->toBe('She reaches for the handset. She calls reception.')
        ->and(array_column($shot->fresh()->storylineKeyframes(), 'beat'))->toBe(['She reaches for the handset.', 'She calls reception.']);
});

it('keeps the written storyline for a plan without beats', function () {
    expect(ShotPlan::storylineFrom([['title' => 'One', 'beat' => 'She waits.'], ['title' => 'Two']], 'She waits and waves.'))->toBe('She waits and waves.');
});

it('keeps the parts of the place a keyframe moves, in plain words, also when they are not in the cast', function () {
    $shot = Shot::factory()->for(Project::factory())->create()->load('project');

    ShotPlan::apply($shot, ['kind' => 'scene', 'storyline' => 'The barrier opens.', 'seconds' => 4, 'keyframes' => [
        ['title' => 'Closed', 'description' => 'The barrier is down.', 'elements' => [], 'moving_parts' => []],
        ['title' => 'Opens', 'description' => 'The barrier arm swings up.', 'elements' => [], 'moving_parts' => [' barrier arm ', 'barrier arm']],
    ]]);

    expect($shot->fresh()->storylineKeyframes()[1]['moving_parts'])->toBe(['barrier arm']);
});
