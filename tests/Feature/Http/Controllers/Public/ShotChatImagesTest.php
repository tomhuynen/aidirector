<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\AdjustPlateOption;
use App\Jobs\ChangePlace;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
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
    return ['reply' => 'Fine.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [], 'remove_shots' => [], 'changes' => [], 'choice' => ['action' => 'none', 'option' => 0], 'proposal' => null, ...$overrides];
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

/**
 * The shot waiting for the director to choose one of three places.
 *
 * @return list<\Spatie\MediaLibrary\MediaCollections\Models\Media>
 */
function placesToChoose(Shot $shot): array
{
    $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
    $shot->keyframes()->where('position', '>', 1)->delete();

    return array_map(fn(int $n) => $shot->addMediaFromString(drawnImagePng())->usingFileName("place-{$n}.png")->toMediaCollection(Shot::PLATE_OPTIONS), [1, 2, 3]);
}

describe('choosing in the chat', function () {
    it('sees the places in order and chooses the one the director names', function () {
        $shot = drawnShot($this->project);
        $places = placesToChoose($shot);
        PlanDirector::fake([imagesReply(['reply' => 'Place 2 it is.', 'choice' => ['action' => 'choose', 'option' => 2]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the middle one'])
            ->assertOk()
            ->assertJsonPath('reload', true)
            ->assertJsonPath('messages.3.changes.0', 'Place 2 chosen');

        PlanDirector::assertPrompted(fn($prompt) => count($prompt->attachments) === 3
            && str_contains($prompt->prompt, 'Drawn: not yet: the director chooses the place first. On screen and attached in this order: 3 empty places'));
        expect($shot->fresh()->getFirstMedia(Shot::PLATE)->getCustomProperty('option'))->toBe($places[1]->id);
        // Keyframe 1 has no people, so the place is keyframe 1 and the others are drawn straight away.
        Bus::assertDispatched(GenerateRemainingKeyframes::class);
    });

    it('adjusts a place by its number, adding the adjusted one', function () {
        $shot = drawnShot($this->project);
        $places = placesToChoose($shot);
        PlanDirector::fake([imagesReply(['changes' => [['keyframe' => 0, 'option' => 3, 'change' => 'Put the bin left of the door.']]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the third, but with the bin left of the door'])
            ->assertOk()
            ->assertJsonPath('reload', true);

        Bus::assertDispatched(AdjustPlateOption::class, fn(AdjustPlateOption $job) => $job->option === $places[2]->id && $job->instruction === 'Put the bin left of the door.');
    });

    it('takes a change to keyframe 1 as a change to the only option, such as in a close-up', function () {
        $shot = drawnShot($this->project);
        $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
        $shot->keyframes()->where('position', '>', 1)->delete();
        $option = $shot->keyframes()->firstOrFail()->render();
        PlanDirector::fake([imagesReply(['reply' => 'I redraw her eyes.', 'changes' => [['keyframe' => 1, 'option' => 0, 'change' => 'Both eyes are visible.', 'place' => false, 'part' => '']]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'no, the eyes are gone and the drawing arm has a strange angle'])
            ->assertOk()
            ->assertJsonPath('messages.3.changes', ['Option 1: Both eyes are visible.']);

        Bus::assertDispatched(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->option === $option->id && $job->instruction === 'Both eyes are visible.');
    });

    it('draws more places when asked', function () {
        $shot = drawnShot($this->project);
        placesToChoose($shot);
        PlanDirector::fake([imagesReply(['choice' => ['action' => 'more', 'option' => 0]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'show me some more'])
            ->assertOk();

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
        Bus::assertDispatched(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->more);
    });

    it('confirms keyframe 1 on the chosen place with a yes, or goes back to the places', function () {
        $shot = drawnShot($this->project);
        $places = placesToChoose($shot);
        $places[0]->copy($shot, Shot::PLATE)->setCustomProperty(Shot::PLATE_CHOSEN, true)->save();
        $first = $shot->keyframes()->where('position', 1)->firstOrFail();
        PlanDirector::fake([
            imagesReply(['choice' => ['action' => 'choose', 'option' => 1]]),
            imagesReply(['choice' => ['action' => 'another_place', 'option' => 0]]),
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes'])
            ->assertOk()
            ->assertJsonPath('messages.3.changes.0', 'Keyframe 1 confirmed');

        PlanDirector::assertPrompted(fn($prompt) => count($prompt->attachments) === 1 && str_contains($prompt->prompt, 'Drawn: only keyframe 1, drawn on the chosen place'));
        Bus::assertDispatched(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => ! $job->onlyFirst);
        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING);

        $shot->refresh()->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'another place please'])
            ->assertOk();

        expect($shot->fresh()->hasChosenPlate())->toBeFalse()
            ->and($first->fresh()->renders()->count())->toBe(0);
    });

    it('goes back to the places for the same plan once the keyframes are drawn', function () {
        $shot = drawnShot($this->project);
        $plan = $shot->storylineKeyframes();
        PlanDirector::fake([imagesReply(['reply' => 'Back to the places, same plan.', 'choice' => ['action' => 'another_place', 'option' => 0]])]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'pick a place again, same plan', 'target' => 'keyframe', 'keyframe' => $shot->keyframes()->first()->sqid])
            ->assertOk()
            ->assertJsonPath('reload', true)
            ->assertJsonPath('messages.3.changes.0', 'Back to the places');

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->storylineKeyframes())->toEqual($plan)
            ->and($shot->keyframes()->get()->every(fn(Keyframe $keyframe) => $keyframe->render() === null))->toBeTrue();
        Bus::assertDispatched(GenerateKeyframes::class);
    });

    it('says when the places are ready', function () {
        $shot = drawnShot($this->project);
        placesToChoose($shot);
        $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_PENDING])->save();

        GenerateKeyframes::finishPlates($shot->id);

        expect(collect($shot->fresh()->plan_chat)->last())->toBe(['role' => 'assistant', 'text' => 'The places are ready. Click the one you like or tell me which one, or ask me for more places.']);
    });
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

it('keeps what is drawn when the same plan is given again, such as for "draw the others"', function () {
    $shot = drawnShot($this->project);
    $shot->forceFill(['kind' => 'scene', 'chosen_storyline' => ['title' => 'Gate', 'storyline' => 'The visitor walks on.']])->save();
    $renders = $shot->keyframes()->pluck('render_id')->all();
    $version = $shot->fresh()->plan_version;
    PlanDirector::fake([imagesReply(['reply' => 'I draw the others.', 'proposal' => [
        'takeaway' => '', 'kind' => 'scene', 'storyline' => 'The visitor walks on.', 'seconds' => 3, 'setting_from' => null,
        'keyframes' => array_map(fn(array $keyframe) => [...$keyframe, 'spatial' => '', 'elements' => []], $shot->storylineKeyframes()),
    ]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'keyframe one is correct now render the others again'])
        ->assertOk();

    expect($shot->fresh()->plan_version)->toBe($version)
        ->and($shot->keyframes()->pluck('render_id')->all())->toBe($renders);
    Bus::assertNotDispatched(GenerateKeyframes::class);
});

it('makes a change to the place itself in the empty place, from that keyframe on', function () {
    $shot = drawnShot($this->project);
    $shot->addMediaFromString(drawnImagePng())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    PlanDirector::fake([imagesReply(['reply' => 'I take the handle off the door for the whole shot.', 'changes' => [
        ['keyframe' => 1, 'option' => 0, 'change' => 'The door has no handle.', 'place' => true, 'part' => 'door handle'],
    ]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the door handle should not be visible'])
        ->assertOk()
        ->assertJsonPath('messages.3.changes', ['The place, from keyframe 1 on: The door has no handle.']);

    expect($shot->fresh()->placeEdits())->toEqual([['from' => 1, 'change' => 'The door has no handle.', 'part' => 'door handle']])
        ->and($shot->keyframes()->where('rendering', true)->count())->toBe(3);
    // Nobody is drawn again: the keyframes are put onto the changed place.
    Bus::assertDispatched(ChangePlace::class, fn(ChangePlace $job) => $job->from === 1);
    Bus::assertNotDispatched(TweakKeyframeImage::class);
});

it('changes a finished keyframe while others are still drawn, and says which one has to wait', function () {
    $shot = drawnShot($this->project);
    $shot->forceFill(['status' => ShotStatus::KEYFRAMES_PENDING])->save();
    $shot->keyframes()->where('position', 3)->update(['rendering' => true]);
    PlanDirector::fake([imagesReply(['reply' => 'I am changing them.', 'changes' => [
        ['keyframe' => 1, 'option' => 0, 'change' => 'He smiles.'],
        ['keyframe' => 3, 'option' => 0, 'change' => 'He waves.'],
    ]])]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'make him smile in 1 and wave in 3'])
        ->assertOk()
        ->assertJsonPath('reload', true)
        ->assertJsonPath('messages.3.text', 'I am changing them. (Keyframe 3 is still being drawn; I can change it once it is ready.)')
        ->assertJsonPath('messages.3.changes', ['Keyframe 1: He smiles.']);

    Bus::assertChained([fn(TweakKeyframeImage $job) => $job->keyframe->position === 1]);
});
