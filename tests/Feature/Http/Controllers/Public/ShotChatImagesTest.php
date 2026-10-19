<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Enums\Disk;
use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\ChangeCastMember;
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
    return ['reply' => 'Fine.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'direct' => '', 'remove_shots' => [], 'changes' => [], 'proposal' => null, ...$overrides];
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

    expect($second->fresh()->isBusy())->toBeTrue()
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

it('adjusts the selected option for keyframe 1 while the options wait for a choice', function () {
    $shot = drawnShot($this->project);
    $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
    $first = $shot->keyframes()->where('position', 1)->firstOrFail();
    $option = $first->render();
    PlanDirector::fake([imagesReply(['changes' => [['keyframe' => 0, 'change' => 'Put the bin left of the door.', 'description' => '']]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the bin should be left of the door', 'target' => 'option', 'option' => $option->id])
        ->assertOk()
        ->assertJsonPath('reload', true);

    Bus::assertDispatched(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->is($first) && $job->option === $option->id && $job->instruction === 'Put the bin left of the door.');
});

it('starts the plan over when a new plan is agreed for a drawn shot', function () {
    $shot = drawnShot($this->project);
    PlanDirector::fake([imagesReply(['proposal' => [
        'title' => '', 'kind' => 'scene', 'storyline' => 'He waits behind the line.', 'seconds' => 3, 'setting_from' => null,
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

describe('with one of the cast selected', function () {
    beforeEach(function () {
        $this->shot = drawnShot($this->project);
        $this->second = $this->shot->keyframes()->where('position', 2)->firstOrFail();
        $this->second->forceFill(['status' => KeyframeStatus::READY, 'layout' => ['cast' => [['name' => 'Visitor', 'box' => [300, 400, 900, 600], 'facing' => 'facing the gate', 'pose' => 'standing', 'changed' => true]]]])->save();
    });

    it('proposes a change that restages one of the cast as their fields, also when no one is selected', function () {
        $this->second->restage(App\Support\Shots\KeyframeStaging::fromArray(['cast' => [['name' => 'Visitor', 'stands' => 'left', 'faces' => 'right', 'looks_at' => 'the guard', 'holds' => 'the phone', 'hand' => 'right', 'doing' => 'holds it at the hip']]]))->save();
        $member = ['name' => 'Visitor', 'stands' => 'left', 'faces' => 'away', 'looks_at' => 'the vessel', 'holds' => 'the phone', 'hand' => 'right', 'doing' => 'aims it at the vessel'];
        PlanDirector::fake([imagesReply(['reply' => 'I will turn her toward the vessel. Shall I?', 'changes' => [['keyframe' => 2, 'change' => 'The visitor turns away from the camera and aims the phone at the vessel.', 'member' => $member]]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $this->shot]), ['message' => 'make her look at the vessel and point the phone at it', 'target' => 'keyframe', 'keyframe' => $this->second->sqid])
            ->assertOk()
            ->assertJsonPath('messages.3.proposed.name', 'Visitor')
            ->assertJsonPath('messages.3.proposed.keyframe', $this->second->sqid)
            ->assertJsonPath('messages.3.proposed.fields', [
                ['field' => 'faces', 'from' => 'right', 'to' => 'away'],
                ['field' => 'looks_at', 'from' => 'the guard', 'to' => 'the vessel'],
                ['field' => 'doing', 'from' => 'holds it at the hip', 'to' => 'aims it at the vessel'],
            ])
            ->assertJsonPath('messages.3.changes', null);

        Bus::assertNothingDispatched();
        expect((string) (new PlanDirector($this->shot, []))->instructions())->toContain('when the change cannot be made while a field stays as it is')
            ->toContain('give no change yet; say in one sentence what conflicts and ask whether to change that field too');
    });

    it('proposes the change to them, and makes it only once the director confirms it', function () {
        $this->second->restage(App\Support\Shots\KeyframeStaging::fromArray(['cast' => [['name' => 'Visitor', 'stands' => 'centre', 'faces' => 'right', 'looks_at' => 'the badge', 'holds' => 'the badge', 'hand' => 'right', 'doing' => 'holds it up']]]))->save();
        $member = ['name' => 'Visitor', 'stands' => 'centre', 'faces' => 'away', 'looks_at' => 'the gate', 'holds' => 'the badge', 'hand' => 'right', 'doing' => 'holds it up'];
        PlanDirector::fake([imagesReply(['reply' => 'I will turn the visitor toward the gate. Shall I?', 'changes' => [['keyframe' => 0, 'change' => 'The visitor turns to look at the gate.', 'member' => [...$member, 'name' => 'visitor']]]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $this->shot]), ['message' => 'look at the gate', 'target' => 'keyframe', 'keyframe' => $this->second->sqid, 'member' => 'visitor'])
            ->assertOk()
            ->assertJsonPath('reload', false)
            ->assertJsonPath('messages.2.about', 'Visitor in keyframe 2, Badge shown, outlined in the image')
            ->assertJsonPath('messages.3.proposed', ['keyframe' => $this->second->sqid, 'name' => 'Visitor', 'change' => 'The visitor turns to look at the gate.', 'member' => $member, 'fields' => [
                ['field' => 'faces', 'from' => 'right', 'to' => 'away'],
                ['field' => 'looks_at', 'from' => 'the badge', 'to' => 'the gate'],
            ]]);

        Bus::assertNothingDispatched();
        expect($this->second->fresh()->isBusy())->toBeFalse();
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Selected: Visitor in keyframe 2')
            && str_contains($prompt->prompt, 'their fields now: {"name":"Visitor","stands":"centre","faces":"right","looks_at":"the badge"'));
        expect((string) (new PlanDirector($this->shot, []))->instructions())->toContain('read in the attached image where it is from them and set faces by the rule for faces')
            ->toContain('what lies behind them, deeper in the frame or in the background, means away');

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.change', [$this->project, $this->shot]), ['turn' => 3, 'apply' => true, 'following' => true])
            ->assertOk()
            ->assertJsonPath('messages.3.proposed.state', 'made');

        expect($this->second->fresh()->status)->toBe(KeyframeStatus::DRAWING)
            ->and($this->second->fresh()->staged()->cast)->toBe([$member])
            ->and($this->second->fresh()->description)->toBe('The Visitor stands in the centre, has the back to the camera, looks at the gate, holds the badge in the right hand and holds it up.')
            ->and($this->shot->fresh()->storylineKeyframes()[1]['description'])->toBe($this->second->fresh()->description);
        Bus::assertDispatched(ChangeCastMember::class, fn(ChangeCastMember $job) => $job->keyframe->is($this->second) && $job->name === 'Visitor' && $job->change === 'The visitor turns to look at the gate.' && $job->following);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.change', [$this->project, $this->shot]), ['turn' => 3, 'apply' => true])
            ->assertJsonValidationErrors('turn');
    });

    it('leaves the keyframe as it is when the director cancels the change', function () {
        $this->shot->forceFill(['plan_chat' => [...$this->shot->plan_chat, ['role' => 'director', 'text' => 'a blue jacket'], ['role' => 'assistant', 'text' => 'Shall I?', 'proposed' => ['keyframe' => $this->second->sqid, 'name' => 'Visitor', 'change' => 'The visitor wears a blue jacket.']]]])->save();

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.change', [$this->project, $this->shot]), ['turn' => 3, 'apply' => false])
            ->assertOk()
            ->assertJsonPath('messages.3.proposed.state', 'cancelled');

        Bus::assertNothingDispatched();
        expect($this->second->fresh()->isBusy())->toBeFalse();
    });
});
