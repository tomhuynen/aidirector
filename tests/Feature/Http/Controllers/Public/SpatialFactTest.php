<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

it('adds the spatial fact as the last sentence of what the models get', function () {
    expect(Keyframe::joined('She stands at the line', 'Both feet are behind it.'))->toBe('She stands at the line. Both feet are behind it.')
        ->and(Keyframe::joined('She stands at the line.', ''))->toBe('She stands at the line.')
        ->and((new Keyframe(['description' => 'She waits.', 'spatial' => 'Both feet are outside the shelter.']))->fullDescription())->toBe('She waits. Both feet are outside the shelter.');
});

it('keeps the spatial fact apart in the plan and on the drawn keyframe', function () {
    Queue::fake();
    Bus::fake();
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

    actingAs($this->director, 'director')
        ->post(route('public.shots.plan', [$this->project, $shot]), [
            'storyline' => 'She waits outside the shelter.',
            'framing' => ['spot' => 'At the shelter.', 'seconds' => 5],
            'keyframes' => [['title' => 'Waits', 'description' => 'She holds an unlit cigarette.', 'spatial' => 'Both feet are outside the shelter boundary.']],
        ])
        ->assertSessionHasNoErrors();

    expect($shot->fresh()->storylineKeyframes()[0]['spatial'])->toBe('Both feet are outside the shelter boundary.');

    (new GenerateKeyframes($shot->fresh()))->handle();

    $keyframe = $shot->keyframes()->firstOrFail();

    expect($keyframe->description)->toBe('She holds an unlit cigarette.')
        ->and($keyframe->spatial)->toBe('Both feet are outside the shelter boundary.')
        ->and($keyframe->fullDescription())->toBe('She holds an unlit cigarette. Both feet are outside the shelter boundary.');
});

it('changes the spatial fact from the keyframe panel and renders it again', function () {
    Queue::fake();
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['keyframes' => [['title' => 'Waits', 'description' => 'She waits.', 'spatial' => 'Old fact.']]]]);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'She waits.', 'spatial' => 'Old fact.']);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'She waits.', 'spatial' => 'Both feet are outside the shelter boundary.', 'redraw' => true])
        ->assertSessionHasNoErrors();

    expect($keyframe->fresh()->spatial)->toBe('Both feet are outside the shelter boundary.')
        ->and($shot->fresh()->storylineKeyframes()[0]['spatial'])->toBe('Both feet are outside the shelter boundary.');
});
