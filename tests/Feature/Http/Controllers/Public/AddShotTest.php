<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Enums\Disk;
use App\Enums\KeyframeStatus;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframeOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\ShotPlan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A shot with drawn keyframes, each with its own image.
 */
function shotWithDrawnKeyframes(Project $project, int $position, int $count = 2, array $attributes = []): Shot
{
    $shot = Shot::factory()->for($project)->create(['position' => $position, 'status' => ShotStatus::KEYFRAMES_READY, ...$attributes]);
    $plans = [];

    foreach (range(1, $count) as $number) {
        $image = imagecreatetruecolor(16, 9);
        imagefill($image, 0, 0, imagecolorallocate($image, $number * 40, 0, 0));
        ob_start();
        imagepng($image);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => $number, 'title' => "Moment {$number}", 'description' => "What happens in moment {$number}.", 'status' => KeyframeStatus::READY]);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("k{$number}.png")->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
        $plans[] = ['title' => "Moment {$number}", 'description' => "What happens in moment {$number}."];
    }

    $shot->forceFill(['storyline' => ['keyframes' => $plans]])->save();

    return $shot->fresh();
}

/**
 * A reply of the plan director that adds a shot.
 *
 * @param  array<string, mixed>  $addShot
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addShotReply(array $addShot, array $overrides = []): array
{
    return [
        'reply' => 'Done.',
        'stage' => 'idea',
        'cast' => [],
        'new_elements' => [],
        'adjust_elements' => [],
        'direct' => '',
        'proposal' => null,
        'add_shot' => ['before' => '', 'after' => '', 'title' => 'Sign on the wall', 'kind' => 'close-up', 'idea' => 'The no-camera sign on the wall.', 'starts_from' => ['shot' => '', 'keyframe' => 0], ...$addShot],
        ...$overrides,
    ];
}

function askToAddShot(mixed $test, Shot $shot, string $message = 'add a shot after this one'): Illuminate\Testing\TestResponse
{
    return actingAs($test->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$test->project, $shot]), ['message' => $message])
        ->assertOk();
}

function imageOf(Keyframe $keyframe): string
{
    return Storage::disk($keyframe->render()->disk)->get($keyframe->render()->getPathRelativeToRoot());
}

it('adds a shot right after this one, in its group, starting from an exact copy of its end frame when asked, and draws nothing else', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    $later = Shot::factory()->for($this->project)->create(['position' => 2]);
    PlanDirector::fake([addShotReply([])]);

    askToAddShot($this, $shot)
        ->assertJsonPath('reload', true)
        ->assertJsonPath('messages.1.text', 'Done. SH020 is added after SH010, in the same group; its start frame is a copy of the end frame of SH010. Plan it in its own chat.');

    $added = $this->project->shots()->where('position', 2)->firstOrFail();
    $copy = $added->keyframes()->with('media')->sole();
    $last = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

    expect($later->fresh()->position)->toBe(3)
        ->and($shot->fresh()->group_key)->not->toBeNull()
        ->and($added->group_key)->toBe($shot->fresh()->group_key)
        ->and($added->kind)->toBe(ShotKind::CLOSE_UP)
        ->and($added->status)->toBe(ShotStatus::STORYLINE_READY)
        ->and($added->opensWithCopy())->toBeTrue()
        ->and($added->storylineKeyframes())->toEqual([['title' => 'Moment 2', 'description' => 'What happens in moment 2.', 'copied' => true]])
        ->and($added->plan_chat[0]['text'])->toContain('starts from the end frame of SH010 as it is')
        ->and($copy->position)->toBe(1)
        ->and($copy->status)->toBe(KeyframeStatus::READY)
        ->and($copy->depends_on_id)->toBeNull()
        ->and($copy->render_id)->not->toBe($last->render_id)
        ->and(imageOf($copy))->toBe(imageOf($last))
        ->and($shot->fresh()->keyframes()->count())->toBe(2);

    Queue::assertNotPushed(GenerateKeyframes::class);
    Queue::assertNotPushed(GenerateRemainingKeyframes::class);
    PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Once the director agrees, give it in add_shot'));
});

it('adds an empty shot without a copy when the director does not ask to start from a keyframe', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply(['starts_from' => null])]);

    askToAddShot($this, $shot)->assertJsonPath('messages.1.text', 'Done. SH020 is added after SH010, in the same group. Plan it in its own chat.');

    $added = $this->project->shots()->where('position', 2)->firstOrFail();

    expect($added->group_key)->toBe($shot->fresh()->group_key)
        ->and($added->keyframes()->count())->toBe(0)
        ->and($added->opensWithCopy())->toBeFalse()
        ->and($added->storylineKeyframes())->toBe([])
        ->and($added->plan_chat[0]['text'])->toBe('This shot comes after SH010: The no-camera sign on the wall. Shall we go on from there?');
    PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'starts_from only when the director asks for the new shot to start from a frame'));
});

it('adds a shot before this one, in its group, with the codes as they are after it', function () {
    $previous = shotWithDrawnKeyframes($this->project, 1);
    $shot = shotWithDrawnKeyframes($this->project, 2, attributes: ['group_key' => 'g1']);
    PlanDirector::fake([addShotReply(['before' => 'SH020', 'after' => '', 'starts_from' => ['shot' => '', 'keyframe' => 0]])]);

    askToAddShot($this, $shot, 'add a shot before this one, starting from the last keyframe before it')
        ->assertJsonPath('messages.1.text', 'Done. SH020 is added before SH030, in the same group; its start frame is a copy of the end frame of SH010. Plan it in its own chat.');

    $added = $this->project->shots()->where('position', 2)->firstOrFail();

    expect($shot->fresh()->position)->toBe(3)
        ->and($previous->fresh()->position)->toBe(1)
        ->and($added->group_key)->toBe('g1')
        ->and($previous->fresh()->group_key)->toBeNull()
        ->and($added->plan_chat[0]['text'])->toStartWith('This shot comes before SH030 and starts from the end frame of SH010 as it is')
        ->and(imageOf($added->keyframes()->with('media')->sole()))->toBe(imageOf($previous->keyframes()->with('media')->where('position', 2)->firstOrFail()));
    PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'before is the code of the shot it goes in front of'));
});

it('adds the shot after another shot and starts it from a keyframe of a third', function () {
    $first = shotWithDrawnKeyframes($this->project, 1, attributes: ['group_key' => 'g1']);
    $talking = shotWithDrawnKeyframes($this->project, 2);
    PlanDirector::fake([addShotReply(['after' => 'SH010', 'starts_from' => ['shot' => 'SH020', 'keyframe' => 2]])]);

    askToAddShot($this, $talking, 'add a shot after SH010 starting from keyframe 2 of SH020');

    $added = $this->project->shots()->where('position', 2)->firstOrFail();

    expect($added->group_key)->toBe('g1')
        ->and($talking->fresh()->position)->toBe(3)
        ->and($talking->fresh()->keyframes()->count())->toBe(2)
        ->and(imageOf($added->keyframes()->with('media')->sole()))->toBe(imageOf($talking->keyframes()->with('media')->where('position', 2)->firstOrFail()));
});

it('never replaces this shot when a shot is added, also when a plan comes with it', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply([], ['proposal' => [
        'title' => 'Sign', 'kind' => 'close-up', 'storyline' => 'The sign.', 'seconds' => 3, 'setting_from' => null,
        'keyframes' => [['title' => 'Sign', 'description' => 'The sign on the wall.', 'spatial' => '', 'elements' => []]],
    ]])]);

    askToAddShot($this, $shot);

    expect($shot->fresh()->keyframes()->pluck('title')->all())->toBe(['Moment 1', 'Moment 2'])
        ->and($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);
});

it('does not start a montage from a copy', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply(['kind' => 'montage'])]);

    askToAddShot($this, $shot)->assertJsonPath('messages.1.text', 'Done. SH020 is added after SH010, in the same group. A montage draws every still on its own, so it does not start from a copy. Plan it in its own chat.');

    $added = $this->project->shots()->where('position', 2)->firstOrFail();

    expect($added->keyframes()->count())->toBe(0)
        ->and($added->opensWithCopy())->toBeFalse();
});

it('keeps the copied start frame when the plan is agreed and drawn, and draws on from it', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply([])]);
    askToAddShot($this, $shot);
    $added = $this->project->shots()->where('position', 2)->firstOrFail();
    $copy = $added->keyframes()->sole();

    ShotPlan::apply($added, [
        'title' => 'Sign close-up',
        'kind' => 'close-up',
        'storyline' => 'Her phone lowers in front of the sign.',
        'seconds' => 3,
        'setting_from' => null,
        'keyframes' => [
            ['title' => 'Rewritten', 'summary' => 'A different first moment.', 'place' => 'At the wall.', 'cast' => [], 'spatial' => '', 'elements' => []],
            ['title' => 'Phone lowers', 'summary' => 'The phone lowers in front of the sign.', 'place' => 'At the wall.', 'cast' => [], 'spatial' => '', 'elements' => []],
        ],
    ]);
    $added->refresh();

    expect(array_column($added->storylineKeyframes(), 'title'))->toBe(['Moment 2', 'Phone lowers']);

    Bus::fake();
    (new GenerateKeyframes($added))->handle();

    $keyframes = $added->keyframes()->get();

    expect($keyframes->pluck('id')->first())->toBe($copy->id)
        ->and($keyframes->first()->render_id)->toBe($copy->render_id)
        ->and($keyframes->pluck('title')->all())->toBe(['Moment 2', 'Phone lowers'])
        ->and($keyframes[1]->depends_on_id)->toBe($copy->id)
        ->and($added->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);

    Bus::assertDispatched(GenerateRemainingKeyframes::class);
    Bus::assertNotDispatched(GenerateKeyframeOption::class);
    Bus::assertBatchCount(0);
});

it('keeps the copied start frame when the plan starts over', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply([])]);
    askToAddShot($this, $shot);
    $added = $this->project->shots()->where('position', 2)->firstOrFail();
    $added->keyframes()->create(['position' => 2, 'title' => 'Later', 'description' => 'Later.', 'status' => KeyframeStatus::READY]);

    $added->startPlanOver();

    expect($added->keyframes()->pluck('title')->all())->toBe(['Moment 2']);
});

it('tells the plan director that the start frame is a fixed copy', function () {
    $shot = shotWithDrawnKeyframes($this->project, 1);
    PlanDirector::fake([addShotReply([])]);
    askToAddShot($this, $shot);
    $added = $this->project->shots()->where('position', 2)->firstOrFail();

    expect((new PlanDirector($added, []))->promptFor('go on'))
        ->toContain('Setting: the start frame is fixed: an exact copy of a frame of the shot this one follows, never drawn again.')
        ->toContain("1. Start frame: Moment 2\n");
});
