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
    App\Ai\Agents\PlanDirector::fake([[
        'reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [],
        'proposal' => ['takeaway' => '', 'kind' => 'scene', 'storyline' => 'The plan.', 'seconds' => 4, 'setting_from' => null, 'keyframes' => [['title' => 'Waits', 'description' => 'She holds an unlit cigarette.', 'spatial' => 'Both feet are outside the shelter boundary.', 'elements' => []]]],
    ]]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['framing' => ['spot' => 'At the shelter.', 'seconds' => 5], 'keyframes' => []]]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes'])
        ->assertOk();

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

it('has the storyline follow a changed description before the keyframe is reviewed', function () {
    Queue::fake();
    App\Ai\Agents\StorylineFollower::fake([['storyline' => 'The contractor lays the permit on the dashboard and drives on.']]);
    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'chosen_storyline' => ['title' => 'Permit', 'storyline' => 'The contractor puts the permit against the glass and drives on.'],
        'storyline' => ['keyframes' => [['title' => 'Permit', 'description' => 'He puts the permit against the glass.']]],
    ]);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'He puts the permit against the glass.']);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He lays the permit on the dashboard.', 'redraw' => true])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(App\Jobs\FollowStoryline::class, fn($job) => $job->before === 'He puts the permit against the glass.');

    App\Jobs\FollowStoryline::follow($keyframe->fresh(), 'He puts the permit against the glass.');

    expect($shot->fresh()->chosen_storyline['storyline'])->toBe('The contractor lays the permit on the dashboard and drives on.');
    App\Ai\Agents\StorylineFollower::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Keyframe 1 now: He lays the permit on the dashboard.'));
});
