<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\SceneDirection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function closeUpShot(Project $project): Shot
{
    return Shot::factory()->for($project)->create([
        'kind' => ShotKind::CLOSE_UP,
        'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
        'chosen_storyline' => ['storyline' => 'She clips the badge high on her chest.'],
        'storyline' => ['framing' => ['spot' => 'The chest of her hi-vis vest.', 'seconds' => 4], 'keyframes' => [
            ['title' => 'Badge In Hand', 'description' => 'The vest pocket, the badge held in her right hand.', 'spatial' => 'The badge is a hand away from the pocket.'],
            ['title' => 'Badge Clipped', 'description' => 'The badge clipped on the vest pocket.', 'spatial' => 'The badge is high and centred on the chest.'],
        ]],
    ]);
}

it('plans a close-up by its own rules', function () {
    $shot = Shot::factory()->for($this->project)->make(['kind' => ShotKind::CLOSE_UP]);
    $instructions = (string) (new StorylineWriter($shot))->instructions();

    expect($instructions)
        ->toContain('This shot is a close-up: give close-up as the kind.')
        ->toContain('show only as much of them as the action needs, such as the chest and hands, never the whole figure')
        ->not->toContain('Keyframe 1 sets the camera for the whole shot');
});

it('starts from options for the start frame drawn with the hands and the object', function () {
    Bus::fake();
    $shot = closeUpShot($this->project);

    (new GenerateKeyframes($shot))->handle();

    Bus::assertBatched(fn($batch) => $batch->jobs->every(fn($job) => $job instanceof App\Jobs\GenerateKeyframeOption));
});

it('adds hands that are not in the start frame yet, and checks it as a close-up', function () {
    $shot = closeUpShot($this->project);
    $shot->load('project');
    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[1], new App\Ai\KeyframeReferences(
        first: Laravel\Ai\Files\Image::fromStorage('x.png', Disk::TENANT->value),
        castNames: ['Helmeted visitor'],
        firstShowsCast: false,
    )))
        ->toContain('Add the hands and forearms of Helmeted visitor into it')
        ->toContain('The badge clipped on the vest pocket. The badge is high and centred on the chest.');

    $keyframe = App\Models\Keyframe::factory()->for($shot)->create(['position' => 2]);

    expect((string) (new KeyframeChecker($keyframe, ['the keyframe to check']))->instructions())->toContain('This keyframe is a close-up')
        ->toContain('What the crop leaves out, such as the head, a helmet, a hat or the face, is never missing');
});

it('frames the start frame of a close-up tight on the object, without the scene staging', function () {
    $shot = closeUpShot($this->project);
    $shot->load('project');

    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new App\Ai\KeyframeReferences()))
        ->toContain('the object fills about half of the frame')
        ->toContain('the face is cut off at the top edge or out of the frame')
        ->toContain('one layer, never a second jacket or shirt')
        ->not->toContain('The ground near the feet is plain')
        ->not->toContain('or the head and shoulders');
});

/**
 * A shot before the close-up, planned together with it, that ends on a drawn keyframe.
 */
function shotBefore(Project $project, string $group): Shot
{
    $before = Shot::factory()->for($project)->create(['position' => 1, 'group_key' => $group, 'status' => ShotStatus::KEYFRAMES_READY]);

    foreach ([1, 2] as $position) {
        $keyframe = App\Models\Keyframe::factory()->for($before)->create(['position' => $position]);
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("kf{$position}.png")->toMediaCollection(App\Models\Keyframe::RENDERS);
    }

    return $before;
}

it('continues a close-up from the end frame of the shot before it in its group', function () {
    $before = shotBefore($this->project, 'group-1');
    $shot = closeUpShot($this->project);
    $shot->forceFill(['position' => 2, 'group_key' => 'group-1'])->save();

    expect($shot->settingFrom())->toMatchArray(['keyframe' => Shot::LAST_KEYFRAME, 'chosen' => false])
        ->and($shot->settingWaitsFor())->toBe('your confirmation of the end frame of SH010')
        ->and($shot->settingFrom()['shot']->is($before))->toBeTrue();

    $before->confirmEndFrame();

    expect($shot->settingWaitsFor())->toBeNull();

    $shot->load('keyframes');
    $first = App\Models\Keyframe::factory()->for($shot)->create(['position' => 1]);
    $references = app(App\Ai\KeyframePainter::class)->referencesFor($first->load('elements', 'shot'), collect([$first]));

    expect($references->labels())->toContain('The setting: the end frame of SH010, the shot before')
        ->and(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], $references))
        ->toContain('attached image is the end frame of SH010, the shot before. This keyframe shows exactly that instant from another camera position, as a match cut')
        ->toContain('Keep the screen direction');
});

it('takes no setting from a shot that is not planned together with it, unless chosen', function () {
    shotBefore($this->project, 'group-1');
    $shot = closeUpShot($this->project);
    $shot->forceFill(['position' => 2])->save();

    expect($shot->settingFrom())->toBeNull();

    $shot->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'setting_from' => ['shot_id' => Shot::query()->where('position', 1)->value('id'), 'keyframe' => 0]]);

    expect($shot->fresh()->settingFrom())->toMatchArray(['keyframe' => 0, 'chosen' => true]);
});

it('lets the plan chat see the other shots and take the setting of one by its code', function () {
    $before = shotBefore($this->project, 'group-1');
    $before->forceFill(['title' => 'Arrival at reception', 'chosen_storyline' => ['storyline' => 'The visitor walks up to the counter.'], 'storyline' => ['keyframes' => [['title' => 'At The Counter', 'description' => 'The visitor holds out the broken badge holder over the counter.']]]])->save();
    $shot = Shot::factory()->for($this->project)->create(['position' => 2, 'kind' => ShotKind::CLOSE_UP, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);
    App\Ai\Agents\PlanDirector::fake([
        ['reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'direct' => '', 'proposal' => [
            'title' => 'Hand the badge over',
            'kind' => 'close-up',
            'storyline' => 'Hands pass the broken badge holder over the counter.',
            'keyframes' => [['title' => 'Hand Over', 'description' => 'Hands pass the broken badge holder over the counter.', 'spatial' => '', 'elements' => []]],
            'setting_from' => ['shot' => 'SH010', 'keyframe' => 1],
        ]],
    ]);

    $this->actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'same setting as the previous shot, a close-up of the hand-over'])
        ->assertOk()
        ->assertJsonPath('messages.1.proposal.settingFrom', 'SH010 · keyframe 1');

    expect($shot->fresh()->storyline['setting_from'])->toBe(['shot_id' => $before->id, 'keyframe' => 1]);
    App\Ai\Agents\PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '- SH010 (scene): Arrival at reception')
        && str_contains($prompt->prompt, 'SH010 (scene, the shot before this one)')
        && str_contains($prompt->prompt, '1. At The Counter: The visitor holds out the broken badge holder over the counter.'));
});

describe('sequences', function () {
    it('has every shot after the first start from the people of the end frame of the last shot with people before it, in its own place', function () {
        $project = $this->project;
        $frame = fn(string $place, string $holds = '') => ['title' => 'Frame', 'summary' => '', 'place' => $place, 'cast' => $holds === '' ? [] : [['name' => 'Visitor', 'stands' => '', 'faces' => '', 'looks_at' => '', 'holds' => $holds, 'hand' => 'right', 'doing' => '']], 'spatial' => '', 'elements' => []];
        $shots = [
            ['title' => 'Report it', 'kind' => 'scene', 'purpose' => 'p', 'notice' => 'n', 'start' => $frame('The counter.'), 'action' => 'He walks up to the counter.', 'end' => $frame('The counter.', 'the badge'), 'seconds' => 4, 'cut' => 'c', 'shows' => [1]],
            ['title' => 'The sign', 'kind' => 'insert', 'purpose' => 'p', 'notice' => 'n', 'start' => $frame('The sign above the counter.'), 'action' => 'The sign shows.', 'end' => $frame('The sign above the counter.'), 'seconds' => 2, 'cut' => 'c', 'shows' => [2]],
            ['title' => 'Hand it over', 'kind' => 'close-up', 'purpose' => 'p', 'notice' => 'n', 'start' => $frame('The counter, close on the hands.'), 'action' => 'Hands pass the badge over.', 'end' => $frame('The counter.'), 'seconds' => 2, 'cut' => 'c', 'shows' => [2]],
            ['title' => 'Wear it', 'kind' => 'scene', 'purpose' => 'p', 'notice' => 'n', 'start' => $frame('The counter, in the view of shot 1.', 'a new badge'), 'action' => 'He walks away from the counter.', 'end' => $frame('The doors.'), 'seconds' => 4, 'cut' => 'c', 'shows' => [3]],
        ];
        $direction = SceneDirection::fromArray(['title' => 'Badge', 'change' => 'A new badge.', 'facts' => ['a', 'b', 'c'], 'context' => [], 'must_not' => [], 'shots' => $shots], $project);
        $shot = Shot::factory()->for($project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

        $made = $direction->apply($project, $shot);

        expect($direction->problems())->toBe([])
            ->and($direction->shots[1]['start']['description'])->toBe('The sign above the counter.')
            ->and($direction->shots[2]['start']['description'])->toBe('The counter, close on the hands. The Visitor holds the badge in the right hand.')
            ->and($direction->shots[3]['start']['description'])->toBe('The counter, in the view of shot 1.')
            ->and($made)->toHaveCount(4)
            ->and($made[1]->storyline['setting_from'])->toBe(['shot_id' => $shot->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($made[1]->continuesPose())->toBeFalse()
            ->and($made[2]->storyline['setting_from'])->toBe(['shot_id' => $shot->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($made[2]->storylineKeyframes()[0]['description'])->toBe('The counter, close on the hands. The Visitor holds the badge in the right hand.')
            ->and($made[2]->continuesPose())->toBeTrue()
            ->and($made[3]->storyline['setting_from'])->toBe(['shot_id' => $made[2]->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($made[3]->storylineKeyframes()[0]['description'])->toBe('The counter, in the view of shot 1.');
    });

    it('waits to draw until the start frame of the earlier shot is chosen, then draws in that same place', function () {
        Bus::fake();
        $first = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::FIRST_KEYFRAME_READY]);
        $later = Shot::factory()->for($this->project)->create([
            'position' => 2,
            'kind' => ShotKind::SCENE,
            'status' => ShotStatus::STORYLINE_READY,
            'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 3], 'keyframes' => [['title' => 'Leaves', 'description' => 'He walks away from the counter.']], 'setting_from' => ['shot_id' => $first->id, 'keyframe' => 0]],
        ]);

        $this->actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.generate', [$this->project, $later]))
            ->assertSessionHasNoErrors();

        expect($later->fresh()->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($later->fresh()->waitsToDraw())->toBeTrue();
        Bus::assertNotDispatched(GenerateKeyframes::class);

        // The start frame of the earlier shot is chosen: the waiting shot is drawn now, in that same place.
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $keyframe = App\Models\Keyframe::factory()->for($first)->create(['position' => 1]);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('k1.png')->toMediaCollection(App\Models\Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
        Shot::drawWaitingShots($this->project->id);

        expect($later->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($later->fresh()->waitsToDraw())->toBeFalse()
            ->and(app(App\Ai\KeyframePainter::class)->settingFor($later->fresh())['label'])->toBe('the place of SH010');
        Bus::assertDispatched(GenerateKeyframes::class, fn($job) => $job->shot->is($later));
    });
});
