<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GeneratePlateOption;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
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
        'chosen_storyline' => ['title' => 'Badge On', 'storyline' => 'She clips the badge high on her chest.'],
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

it('starts from keyframe 1 drawn with the hands and the object, not from an empty place', function () {
    Config::set('pipeline.keyframes.start_with_plate', true);
    Bus::fake();
    $shot = closeUpShot($this->project);

    (new GenerateKeyframes($shot))->handle();

    Bus::assertBatched(fn($batch) => $batch->jobs->every(fn($job) => $job instanceof App\Jobs\GenerateKeyframeOption));
    Bus::assertNotDispatched(GeneratePlateOption::class);
});

it('adds hands that are not in keyframe 1 yet, and checks it as a close-up', function () {
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

    expect((string) (new KeyframeChecker($keyframe, ['the keyframe to check']))->instructions())->toContain('This keyframe is a close-up');
});

it('frames keyframe 1 of a close-up tight on the object, without the scene staging', function () {
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

it('continues a close-up from the last keyframe of the shot before it in its group', function () {
    $before = shotBefore($this->project, 'group-1');
    $shot = closeUpShot($this->project);
    $shot->forceFill(['position' => 2, 'group_key' => 'group-1'])->save();

    expect($shot->settingFrom())->toMatchArray(['keyframe' => Shot::LAST_KEYFRAME, 'chosen' => false])
        ->and($shot->settingWaitsFor())->toBeNull()
        ->and($shot->settingFrom()['shot']->is($before))->toBeTrue();

    $shot->load('keyframes');
    $first = App\Models\Keyframe::factory()->for($shot)->create(['position' => 1]);
    $references = app(App\Ai\KeyframePainter::class)->referencesFor($first->load('elements', 'shot'), collect([$first]));

    expect($references->labels())->toContain('The setting: the last keyframe of SH010, the shot before')
        ->and(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], $references))
        ->toContain('attached image is the last keyframe of SH010, the shot before. This keyframe plays in exactly that place')
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
    $before->forceFill(['title' => 'Arrival at reception', 'chosen_storyline' => ['title' => 'Arrival', 'storyline' => 'The visitor walks up to the counter.'], 'storyline' => ['keyframes' => [['title' => 'At The Counter', 'description' => 'The visitor holds out the broken badge holder over the counter.']]]])->save();
    $shot = Shot::factory()->for($this->project)->create(['position' => 2, 'kind' => ShotKind::CLOSE_UP, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);
    App\Ai\Agents\PlanDirector::fake([
        ['reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'shots' => [], 'proposal' => [
            'takeaway' => 'Hand the broken badge over',
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
    it('records where each shot of a split plays: continuing from the shot before, or in the place of an earlier one', function () {
        App\Ai\Agents\PlanDirector::fake([
            ['reply' => 'Four shots, the last one back at the counter.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [
                ['takeaway' => 'Report it', 'kind' => 'scene', 'idea' => 'He walks up to the counter.', 'setting' => 'new_place', 'same_place_as' => 0],
                ['takeaway' => 'Hand it over', 'kind' => 'close-up', 'idea' => 'Hands pass the badge over.', 'setting' => 'continues', 'same_place_as' => 0],
                ['takeaway' => 'Get a new one', 'kind' => 'close-up', 'idea' => 'A new badge comes back.', 'setting' => 'continues', 'same_place_as' => 0],
                ['takeaway' => 'Wear it', 'kind' => 'scene', 'idea' => 'He walks away from the counter.', 'setting' => 'same_place', 'same_place_as' => 1],
            ]],
        ]);
        $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

        $this->actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes, those four'])
            ->assertJsonPath('reload', true);

        $shots = $this->project->shots()->get();

        expect($shots[1]->storyline['setting_from'])->toBe(['shot_id' => $shot->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($shots[2]->storyline['setting_from'])->toBe(['shot_id' => $shots[1]->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($shots[3]->storyline['setting_from'])->toBe(['shot_id' => $shot->id, 'keyframe' => 0])
            ->and($shots[3]->settingWaitsFor())->toBe('the place of SH010');
    });

    it('waits to draw until the place of the earlier shot is chosen, then draws on that same place', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
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

        // The earlier shot's place is chosen: the waiting shot is drawn now, on that same place.
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $first->addMediaFromString((string) ob_get_clean())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        Shot::drawWaitingShots($this->project->id);

        expect($later->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($later->fresh()->waitsToDraw())->toBeFalse()
            ->and($later->fresh()->inheritedPlate()?->getKey())->toBe($first->media()->where('collection_name', Shot::PLATE)->value('id'));
        Bus::assertDispatched(GenerateKeyframes::class, fn($job) => $job->shot->is($later));

        (new GenerateKeyframes($later->fresh()))->handle();

        expect($later->fresh()->hasChosenPlate())->toBeTrue();
        Bus::assertNotDispatched(GeneratePlateOption::class);
    });
});
