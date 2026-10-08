<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\AdjustPlateOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function drawnImagePng(): string
{
    $image = imagecreatetruecolor(16, 9);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A shot with three drawn keyframes, whose conversation goes on about the images.
 */
function drawnShot(Project $project): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'plan_chat' => [['role' => 'director', 'text' => 'yes'], ['role' => 'assistant', 'text' => 'The plan is written.']],
        'storyline' => ['keyframes' => [
            ['title' => 'At the gate', 'description' => 'The visitor stands at the gate.'],
            ['title' => 'Badge shown', 'description' => 'The visitor holds up the badge.'],
            ['title' => 'Through', 'description' => 'The visitor walks on.'],
        ]],
    ]);

    foreach ([1, 2, 3] as $position) {
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position, 'title' => ['At the gate', 'Badge shown', 'Through'][$position - 1]]);
        $render = $keyframe->addMediaFromString(drawnImagePng())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
    }

    Bus::fake();

    return $shot;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function imagesReply(array $overrides = []): array
{
    return ['reply' => 'Fine.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [], 'remove_shots' => [], 'changes' => [], 'proposal' => null, ...$overrides];
}

it('goes on about the drawn images: the selected one is attached and a clear change is made', function () {
    $shot = drawnShot($this->project);
    $second = $shot->keyframes()->where('position', 2)->firstOrFail();
    PlanDirector::fake([imagesReply(['reply' => 'I am moving the badge to her right hand.', 'changes' => [['keyframe' => 0, 'change' => 'She holds the badge in her right hand.']]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the badge should be in her right hand', 'target' => 'keyframe', 'keyframe' => $second->sqid])
        ->assertOk()
        ->assertJsonPath('reload', true)
        ->assertJsonPath('messages.2.about', 'keyframe 2, Badge shown')
        ->assertJsonPath('messages.3.changes', ['Keyframe 2: She holds the badge in her right hand.']);

    expect($second->fresh()->rendering)->toBeTrue()
        ->and($shot->fresh()->plan_chat)->toHaveCount(4);
    Bus::assertChained([fn(TweakKeyframeImage $job) => $job->keyframe->is($second) && $job->rewrite]);
    // The same conversation, with what was agreed before, and the image it is about.
    PlanDirector::assertPrompted(fn($prompt) => count($prompt->attachments) === 1
        && str_contains($prompt->prompt, 'Drawn: yes. Selected: keyframe 2, Badge shown')
        && str_contains($prompt->prompt, 'You: The plan is written.'));
});

it('changes several keyframes one after the other, in their order', function () {
    $shot = drawnShot($this->project);
    PlanDirector::fake([imagesReply(['changes' => [
        ['keyframe' => 3, 'change' => 'Her jacket is blue.'],
        ['keyframe' => 1, 'change' => 'Her jacket is blue.'],
    ]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'her jacket blue in keyframes 1 and 3', 'target' => 'keyframe', 'keyframe' => $shot->keyframes()->firstOrFail()->sqid])
        ->assertOk();

    Bus::assertChained([
        fn(TweakKeyframeImage $job) => $job->keyframe->position === 1,
        fn(TweakKeyframeImage $job) => $job->keyframe->position === 3,
    ]);
});

it('adjusts the selected place while the places wait for a choice', function () {
    $shot = drawnShot($this->project);
    $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
    $place = $shot->addMediaFromString(drawnImagePng())->usingFileName('place.png')->toMediaCollection(Shot::PLATE_OPTIONS);
    PlanDirector::fake([imagesReply(['changes' => [['keyframe' => 0, 'change' => 'Put the bin left of the door.']]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the bin should be left of the door', 'target' => 'place', 'option' => $place->id])
        ->assertOk()
        ->assertJsonPath('reload', true);

    Bus::assertDispatched(AdjustPlateOption::class, fn(AdjustPlateOption $job) => $job->option === $place->id && $job->instruction === 'Put the bin left of the door.');
});

it('starts the plan over when a new plan is agreed for a drawn shot', function () {
    $shot = drawnShot($this->project);
    PlanDirector::fake([imagesReply(['proposal' => [
        'takeaway' => '', 'kind' => 'scene', 'storyline' => 'He waits behind the line.', 'seconds' => 3, 'setting_from' => null,
        'keyframes' => [['title' => 'Waits', 'description' => 'He waits behind the line.', 'spatial' => '', 'elements' => []]],
    ]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes, plan it again like that', 'target' => 'keyframe', 'keyframe' => $shot->keyframes()->firstOrFail()->sqid])
        ->assertOk();

    $shot->refresh();

    expect($shot->plan_version)->toBe(1)
        ->and($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
        ->and($shot->storylineKeyframes()[0]['title'])->toBe('Waits');
    Bus::assertDispatched(GenerateKeyframes::class);
});

it('says why a change has to wait while something is being drawn', function () {
    $shot = drawnShot($this->project);
    $shot->forceFill(['status' => ShotStatus::KEYFRAMES_PENDING])->save();
    PlanDirector::fake([imagesReply(['reply' => 'I am changing it.', 'changes' => [['keyframe' => 0, 'change' => 'He smiles.']]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'make him smile', 'target' => 'keyframe', 'keyframe' => $shot->keyframes()->firstOrFail()->sqid])
        ->assertOk()
        ->assertJsonPath('reload', false)
        ->assertJsonPath('messages.3.text', 'I am changing it. (I can change it once it is drawn and nothing else is being drawn.)');

    Bus::assertNothingChained();
});
