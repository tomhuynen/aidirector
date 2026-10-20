<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\SceneDirector;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\Briefs\RenderCapabilities;
use App\Ai\KeyframePainter;
use App\Ai\KeyframeReferences;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\DrawKeyframe;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use App\Support\Shots\SceneDirection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A shot of the sequence "seq", with a planned start frame and end frame.
 *
 * @param  array<string, mixed>  $attributes
 */
function sequenceShot(Project $project, int $position, ShotKind $kind, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'position' => $position,
        'kind' => $kind,
        'group_key' => 'seq',
        'status' => ShotStatus::STORYLINE_READY,
        'chosen_storyline' => ['storyline' => 'She reaches for the bag and stops short of the handle.'],
        'storyline' => ['framing' => ['spot' => 'The corridor by the bag.', 'seconds' => 3], 'keyframes' => [
            ['title' => 'Reaching', 'description' => 'Her right hand reaches toward the handle of the bag.', 'spatial' => ''],
            ['title' => 'Stopped', 'description' => 'Her hand stops a few centimetres short of the handle.', 'spatial' => ''],
        ]],
        ...$attributes,
    ]);
}

function sequencePng(int $width = 16): string
{
    $image = imagecreatetruecolor($width, 9);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Draws a render for a keyframe in code and makes it the chosen one.
 */
function addSequenceRender(Keyframe $keyframe, int $width = 16): Media
{
    $render = $keyframe->addMediaFromString(sequencePng($width))->usingFileName("kf{$keyframe->position}-{$width}.png")->toMediaCollection(Keyframe::RENDERS);
    $keyframe->forceFill(['render_id' => $render->id, 'status' => KeyframeStatus::READY])->save();

    return $render;
}

/**
 * Gives a shot its start frame and end frame, drawn, so it is ready.
 */
function drawnSequenceShot(Shot $shot): Shot
{
    foreach ([1, 2] as $position) {
        addSequenceRender(Keyframe::factory()->for($shot)->create(['position' => $position]));
    }

    $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

    return $shot;
}

/**
 * Draws the keyframes the chain queued, in order, as the queue would.
 */
function drawQueuedSequence(Shot $shot): void
{
    foreach ($shot->keyframes()->orderBy('position')->get() as $keyframe) {
        if ($keyframe->refresh()->status !== KeyframeStatus::READY) {
            (new DrawKeyframe($keyframe, $keyframe->draw_version))->handle(app(KeyframePainter::class), app(KeyframeChain::class));
        }
    }
}

it('has every shot in a sequence continue from the last shot with people before it, whatever its kind', function () {
    $scene = sequenceShot($this->project, 1, ShotKind::SCENE);
    $closeUp = sequenceShot($this->project, 2, ShotKind::CLOSE_UP);
    $insert = sequenceShot($this->project, 3, ShotKind::INSERT);
    $wide = sequenceShot($this->project, 4, ShotKind::SCENE);
    $montage = sequenceShot($this->project, 5, ShotKind::MONTAGE);

    expect($scene->settingFrom())->toBeNull()
        ->and($scene->choosesStartFrame())->toBeTrue()
        ->and($closeUp->settingFrom()['shot']->is($scene))->toBeTrue()
        ->and($closeUp->continuesPose())->toBeTrue()
        ->and($closeUp->choosesStartFrame())->toBeFalse()
        ->and($insert->settingFrom()['shot']->is($closeUp))->toBeTrue()
        ->and($insert->continuesPose())->toBeFalse()
        ->and($wide->settingFrom()['shot']->is($closeUp))->toBeTrue()
        ->and($wide->continuesPose())->toBeTrue()
        ->and($montage->settingFrom())->toBeNull()
        ->and($scene->continuedBy()->map->code()->all())->toBe(['SH020'])
        ->and($closeUp->continuedBy()->map->code()->all())->toBe(['SH030', 'SH040']);
});

it('keeps the parts of a merged shot in their sequence', function () {
    $scene = sequenceShot($this->project, 1, ShotKind::SCENE);
    $closeUp = sequenceShot($this->project, 2, ShotKind::CLOSE_UP);
    $merged = Shot::factory()->for($this->project)->create(['position' => 1, 'merge_transition' => App\Enums\ShotTransition::CUT, 'status' => ShotStatus::KEYFRAMES_READY]);
    Shot::query()->whereKey([$scene->id, $closeUp->id])->update(['merged_into_id' => $merged->id]);

    expect($closeUp->fresh()->settingFrom()['shot']->is($scene))->toBeTrue()
        ->and($scene->fresh()->continuedBy()->map->code()->all())->toBe(['SH020'])
        ->and($merged->fresh()->continuedBy())->toBeEmpty();
});

it('waits for the director to confirm the end frame of the shot before, then draws the start frame from it without options', function () {
    Bus::fake();
    $before = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $shot = sequenceShot($this->project, 2, ShotKind::CLOSE_UP);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.generate', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    expect($shot->fresh()->status)->toBe(ShotStatus::STORYLINE_READY)
        ->and($shot->fresh()->waitsToDraw())->toBeTrue()
        ->and($shot->fresh()->waitsFor())->toBe('your confirmation of the end frame of SH010');
    Bus::assertNotDispatched(GenerateKeyframes::class);

    actingAs($this->director, 'director')
        ->post(route('public.shots.end-frame.confirm', [$this->project, $before]))
        ->assertRedirect(route('public.shots.view', [$this->project, $before]));

    expect($before->fresh()->endFrameConfirmed())->toBeTrue()
        ->and($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
        ->and($shot->fresh()->waitsToDraw())->toBeFalse();
    Bus::assertDispatched(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->shot->is($shot));

    (new GenerateKeyframes($shot->fresh()))->handle();

    Bus::assertNothingBatched();
    Bus::assertDispatched(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->shot->is($shot));
    expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
        ->and($shot->keyframes()->pluck('status')->map->value->all())->toBe(['waiting', 'waiting']);
});

it('draws the start frame of a continuation as a match cut on the end frame it continues, and checks it against that frame', function () {
    Config::set('pipeline.keyframe_check', true);
    Queue::fake([DrawKeyframe::class]);
    Image::fake(fn() => base64_encode(sequencePng()));
    KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
    ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

    $before = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $before->confirmEndFrame();
    $source = $before->endFrame()->render();
    $shot = sequenceShot($this->project, 2, ShotKind::CLOSE_UP, ['status' => ShotStatus::KEYFRAMES_PENDING]);

    (new GenerateKeyframes($shot))->handle();
    (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

    expect($shot->keyframes()->pluck('status')->map->value->all())->toBe(['drawing', 'waiting'])
        ->and($shot->keyframes()->firstOrFail()->depends_on_id)->toBe($before->endFrame()->id);

    drawQueuedSequence($shot);

    $first = $shot->keyframes()->with('media')->firstOrFail();
    $sourceBytes = Storage::disk(Disk::TENANT->value)->get($source->getPathRelativeToRoot());
    $masterBytes = Storage::disk(Disk::TENANT->value)->get($before->keyframes()->with('media')->where('position', 1)->firstOrFail()->render()->getPathRelativeToRoot());

    expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
        ->and($first->render())->not->toBeNull()
        ->and($first->source_render_id)->toBe($source->id)
        ->and($first->renders())->toHaveCount(1);

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Her right hand reaches toward the handle of the bag.')
        && $prompt->contains('first attached image is the master plate of this sequence, the start frame of SH010, the first shot of this sequence')
        && $prompt->contains('second attached image is the end frame of SH010, the shot before. This keyframe shows exactly that instant from another camera position, as a match cut')
        && $prompt->contains('Take only the people and the objects from it: the place and the light come from the master plate.')
        && $prompt->attachments->count() === 2
        && $prompt->attachments[0]->content() === $masterBytes
        && $prompt->attachments[1]->content() === $sourceBytes);
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Her hand stops a few centimetres short of the handle.')
        && $prompt->contains('The framing is the same crop at the same distance as the first image'));
    KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), "Image 1: the end frame of SH010, the shot before: the reference for the people's clothing, their poses")
        && str_contains((string) $prompt->agent->instructions(), 'Image 2: the start frame of SH010, the first shot of this sequence, the master plate: the reference for the place')
        && str_contains((string) $prompt->agent->instructions(), 'continues the shot before as a match cut')
        && str_contains((string) $prompt->agent->instructions(), 'is never missing from a frame'));
});

it('confirms the end frame only once it is drawn and checked, and once', function () {
    $shot = sequenceShot($this->project, 1, ShotKind::SCENE, ['status' => ShotStatus::KEYFRAMES_PENDING]);
    sequenceShot($this->project, 2, ShotKind::MEDIUM);

    actingAs($this->director, 'director')
        ->post(route('public.shots.end-frame.confirm', [$this->project, $shot]))
        ->assertSessionHasErrors('confirm');

    drawnSequenceShot($shot)->forceFill(['reviewing' => true])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.end-frame.confirm', [$this->project, $shot]))
        ->assertSessionHasErrors('confirm');

    $shot->forceFill(['reviewing' => false])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.end-frame.confirm', [$this->project, $shot]))
        ->assertSessionHasNoErrors();

    actingAs($this->director, 'director')
        ->post(route('public.shots.end-frame.confirm', [$this->project, $shot]))
        ->assertSessionHasErrors('confirm');

    expect($shot->fresh()->canConfirmEndFrame())->toBeFalse()
        ->and($shot->fresh()->endFrameConfirmed())->toBeTrue();
});

it('drops the confirmation when the end frame changes, and lets the director keep or reset the shots drawn from it', function () {
    $before = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $before->confirmEndFrame();
    $closeUp = drawnSequenceShot(sequenceShot($this->project, 2, ShotKind::CLOSE_UP));
    $closeUp->keyframes()->where('position', 1)->update(['source_render_id' => $before->endFrame()->render()->id]);
    $wide = drawnSequenceShot(sequenceShot($this->project, 3, ShotKind::SCENE));
    $wide->keyframes()->where('position', 1)->update(['source_render_id' => $closeUp->endFrame()->render()->id]);

    expect($before->staleFollowers())->toBeEmpty();

    actingAs($this->director, 'director')
        ->post(route('public.shots.followers.reset', [$this->project, $before]))
        ->assertSessionHasErrors('followers');

    $changed = addSequenceRender($before->endFrame(), 20);

    expect($before->endFrameConfirmed())->toBeFalse()
        ->and($before->staleFollowers()->map->code()->all())->toBe(['SH020']);

    actingAs($this->director, 'director')
        ->post(route('public.shots.followers.keep', [$this->project, $before]))
        ->assertSessionHasNoErrors();

    expect($before->staleFollowers())->toBeEmpty()
        ->and($closeUp->keyframes()->where('position', 1)->value('source_render_id'))->toBe($changed->id)
        ->and($closeUp->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);

    addSequenceRender($before->endFrame(), 24);

    actingAs($this->director, 'director')
        ->post(route('public.shots.followers.reset', [$this->project, $before]))
        ->assertSessionHasNoErrors();

    expect($closeUp->fresh()->status)->toBe(ShotStatus::STORYLINE_READY)
        ->and($closeUp->keyframes()->count())->toBe(0)
        ->and($closeUp->fresh()->waitsToDraw())->toBeTrue()
        ->and($closeUp->fresh()->waitsFor())->toBe('your confirmation of the end frame of SH010')
        ->and($wide->fresh()->status)->toBe(ShotStatus::STORYLINE_READY)
        ->and($wide->keyframes()->count())->toBe(0)
        ->and($wide->fresh()->waitsToDraw())->toBeTrue()
        ->and($before->fresh()->staleFollowers())->toBeEmpty();
});

it('shows the shot its place in the sequence', function () {
    $before = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $shot = sequenceShot($this->project, 2, ShotKind::MEDIUM);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $before]))
        ->assertInertia(fn($page) => $page->component('shots/view')
            ->where('shot.choosesStartFrame', true)
            ->where('shot.sequence.continuesFrom', null)
            ->where('shot.sequence.continuedBy', ['SH020'])
            ->where('shot.sequence.endFrameConfirmed', false)
            ->where('shot.sequence.confirmable', true)
            ->where('shot.sequence.staleFollowers', []));

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->component('shots/view')
            ->where('shot.choosesStartFrame', false)
            ->where('shot.sequence.continuesFrom', 'SH010')
            ->where('shot.sequence.continuedBy', []));
});

it('gives an insert in a sequence the place of the shot before it, without its people', function () {
    $before = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $insert = sequenceShot($this->project, 2, ShotKind::INSERT, ['storyline' => ['framing' => ['spot' => '', 'seconds' => 2], 'keyframes' => [['title' => 'The sign', 'description' => 'The no-camera sign on the wall.', 'spatial' => '']]]]);
    $keyframe = Keyframe::factory()->for($insert)->create(['position' => 1]);
    $insert->load('project');
    $references = app(KeyframePainter::class)->referencesFor($keyframe->load('elements', 'shot'), collect([$keyframe]));

    expect($references->labels())->toContain('The setting: the end frame of SH010, the shot before')
        ->and($references->settingIsMoment)->toBeTrue()
        ->and(KeyframeImageBrief::for($insert, $insert->storylineKeyframes()[0], $references))
        ->toContain('This keyframe plays in exactly that place: keep its walls, surfaces, objects, colours and light, without its people.')
        ->and($insert->settingWaitsFor())->toBe('your confirmation of the end frame of SH010');

    $before->confirmEndFrame();

    expect($insert->settingWaitsFor())->toBeNull();
});

it('writes the match cut into the rules the director, the reviewer and the checker work by', function () {
    $shot = sequenceShot($this->project, 1, ShotKind::SCENE);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2]);
    $shot->load('project');

    expect(RenderCapabilities::keyframePairs())
        ->toContain('as a match on action')
        ->toContain('is mid-action: a hand halfway to the handle')
        ->and((string) (new ShotReviewer($shot, collect()))->instructions())->toContain('is played by the video and is never missing from a frame')
        ->and((string) (new KeyframeChecker($keyframe, ['the keyframe to check']))->instructions())
        ->toContain('is never missing from a frame')
        ->not->toContain('as a match cut')
        ->and((string) (new KeyframeChecker($keyframe, ['the keyframe to check'], matchCut: true))->instructions())->toContain('continues the shot before as a match cut')
        ->and(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[1], new KeyframeReferences(first: Laravel\Ai\Files\Image::fromStorage('x.png', Disk::TENANT->value))))
        ->toContain('never zoom out to show more of the people or the place, and never zoom in')
        ->and(ShotStatus::KEYFRAMES_PENDING->description())->toBe('Drawing keyframes');
});

it('takes the place of every later shot from the master plate, not from the shot before', function () {
    Image::fake(fn() => base64_encode(sequencePng()));
    $master = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $medium = drawnSequenceShot(sequenceShot($this->project, 2, ShotKind::MEDIUM));
    $closeUp = sequenceShot($this->project, 3, ShotKind::CLOSE_UP);
    $keyframe = Keyframe::factory()->for($closeUp)->create(['position' => 1]);
    $closeUp->load('project');

    $references = app(KeyframePainter::class)->referencesFor($keyframe->load('elements', 'shot'), collect([$keyframe]));
    $plate = $master->keyframes()->with('media')->where('position', 1)->firstOrFail()->render();

    expect($closeUp->masterShot()->is($master))->toBeTrue()
        ->and($closeUp->masterPlate()->is($plate))->toBeTrue()
        ->and($master->masterShot())->toBeNull()
        ->and($closeUp->returnShot())->toBeNull()
        ->and($references->view)->toBeNull()
        ->and($references->labels())->toBe(['The master plate: the start frame of SH010, the first shot of this sequence', 'The setting: the end frame of SH020, the shot before'])
        ->and($references->images()[0]->path)->toBe($plate->getPathRelativeToRoot())
        ->and($references->images()[1]->path)->toBe($medium->endFrame()->render()->getPathRelativeToRoot());
});

it('draws a shot that returns to an earlier camera on that camera\'s last frame, with the poses of the shot before', function () {
    Config::set('pipeline.keyframe_check', true);
    Image::fake(fn() => base64_encode(sequencePng()));
    KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
    $wide = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $closeUp = drawnSequenceShot(sequenceShot($this->project, 2, ShotKind::CLOSE_UP));
    $back = sequenceShot($this->project, 3, ShotKind::SCENE, ['status' => ShotStatus::KEYFRAMES_PENDING]);
    $keyframe = Keyframe::factory()->for($back)->create(['position' => 1, 'status' => KeyframeStatus::DRAWING]);
    $view = $wide->endFrame()->render();
    $pose = $closeUp->endFrame()->render();

    expect($back->returnShot()->is($wide))->toBeTrue()
        ->and($back->returnFrame()->is($view))->toBeTrue();

    (new DrawKeyframe($keyframe, $keyframe->draw_version))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

    $disk = Storage::disk(Disk::TENANT->value);

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Edit the first attached image. It is the end frame of SH010: this shot returns to that camera.')
        && $prompt->contains('The second attached image is the end frame of SH020, the shot before: the instant this keyframe continues')
        && ! $prompt->contains('style reference sheet')
        && $prompt->attachments->count() === 2
        && $prompt->attachments[0]->content() === $disk->get($view->getPathRelativeToRoot())
        && $prompt->attachments[1]->content() === $disk->get($pose->getPathRelativeToRoot()));
    KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Image 1: the end frame of SH010, from the same camera: the reference for the place, the camera and the framing, which must match exactly.')
        && str_contains((string) $prompt->agent->instructions(), 'returns to the camera of an earlier shot')
        && ! str_contains((string) $prompt->agent->instructions(), 'The framing differs on purpose'));
    expect($keyframe->fresh()->render())->not->toBeNull()
        ->and($keyframe->fresh()->render()->getCustomProperty(Keyframe::STILLNESS))->not->toBeNull()
        ->and($keyframe->fresh()->source_render_id)->toBe($pose->id);
});

it('keeps the people as they are when the shot it returns to is the shot it continues', function () {
    $wide = drawnSequenceShot(sequenceShot($this->project, 1, ShotKind::SCENE));
    $again = sequenceShot($this->project, 2, ShotKind::SCENE);
    $keyframe = Keyframe::factory()->for($again)->create(['position' => 1]);
    $again->load('project');

    $references = app(KeyframePainter::class)->referencesFor($keyframe->load('elements', 'shot'), collect([$keyframe]));

    expect($again->returnShot()->is($wide))->toBeTrue()
        ->and($references->setting)->toBeNull()
        ->and($references->master)->toBeNull()
        ->and($references->labels())->toBe(['The same camera: the end frame of SH010, the image that is edited'])
        ->and(KeyframeImageBrief::for($again, $again->storylineKeyframes()[0], $references))->toContain('The people and the objects stay exactly as they are in the first image.');
});

it('starts a match cut with the people of the end frame before it, in the place as its own camera sees it', function () {
    $frame = fn(string $place, string $doing) => ['title' => 'Frame', 'summary' => 'She reaches.', 'place' => $place, 'cast' => [['name' => 'Visitor', 'stands' => 'centre', 'faces' => 'right', 'looks_at' => 'the backpack', 'holds' => '', 'hand' => '', 'doing' => $doing]], 'spatial' => 'A clear gap remains above the handle.', 'elements' => []];
    $shot = fn(string $kind, array $start, array $end) => ['title' => 'Shot', 'kind' => $kind, 'purpose' => '', 'notice' => '', 'start' => $start, 'action' => 'She acts.', 'end' => $end, 'seconds' => 2, 'cut' => '', 'shows' => []];

    $direction = SceneDirection::fromArray(['shots' => [
        $shot('close-up', $frame('The corridor floor, the background blurred; the backpack fills the lower part of the frame.', 'reaches'), $frame('The corridor floor, the background blurred; the backpack fills the lower part of the frame.', 'holds her hand above the handle')),
        $shot('scene', $frame('The meeting room corridor, the backpack beside the right wall.', 'stands up straight'), $frame('The empty corridor.', 'walks away')),
    ]], $this->project);

    $start = $direction->shots[1]['start'];

    expect($start['staging']['place'])->toBe('The meeting room corridor, the backpack beside the right wall.')
        ->and($start['staging']['cast'][0]['doing'])->toBe('holds her hand above the handle')
        ->and($start['spatial'])->toBe('A clear gap remains above the handle.')
        ->and($start['description'])->not->toContain('blurred')
        ->not->toContain('fills the lower part');
});

it('adds the place its staging names to the elements of a frame', function () {
    Element::factory()->for($this->project)->create(['type' => ElementType::PLACE, 'name' => 'Meeting Room Corridor']);
    $frame = ['title' => 'Frame', 'summary' => 'She stops.', 'place' => 'Meeting Room Corridor, the backpack beside the right wall.', 'cast' => [], 'spatial' => '', 'elements' => []];

    $direction = SceneDirection::fromArray(['shots' => [
        ['title' => 'Shot', 'kind' => 'scene', 'purpose' => '', 'notice' => '', 'start' => $frame, 'action' => 'She stops.', 'end' => $frame, 'seconds' => 2, 'cut' => '', 'shows' => []],
    ]], $this->project);

    expect($direction->shots[0]['start']['elements'])->toBe(['Meeting Room Corridor'])
        ->and($direction->shots[0]['end']['elements'])->toBe(['Meeting Room Corridor']);
});

it('keeps the start frame the director staged for every shot of a sequence rendered in one clip', function () {
    Config::set('pipeline.models.sequence_video', 'kwaivgi/kling-v3-omni-video');
    $frame = fn(string $place, string $looks) => ['title' => 'Frame', 'summary' => 'She holds the phone.', 'place' => $place, 'cast' => [['name' => 'Visitor', 'stands' => 'centre', 'faces' => 'right', 'looks_at' => $looks, 'holds' => 'the smartphone', 'hand' => 'both', 'doing' => 'holds it at eye level']], 'spatial' => '', 'elements' => []];
    $shot = fn(string $kind, array $start, array $end) => ['title' => 'Shot', 'kind' => $kind, 'start' => $start, 'action' => 'She acts.', 'end' => $end, 'seconds' => 3];

    $direction = SceneDirection::fromArray(['shots' => [
        $shot('scene', $frame('The quay walkway.', 'the naval vessel'), $frame('The quay walkway.', 'the left edge of the frame')),
        $shot('medium', $frame('The quay walkway, the sign on the wall panel behind her.', 'the naval vessel'), $frame('The quay walkway.', 'the sign')),
    ]], $this->project);

    expect($direction->shots[1]['start']['staging']['place'])->toBe('The quay walkway, the sign on the wall panel behind her.')
        ->and($direction->shots[1]['start']['staging']['cast'][0]['looks_at'])->toBe('the naval vessel');
});

it('finds the events no shot depicts and the numbers that point at no fact or event', function () {
    $shot = fn(array $shows, array $depicts) => ['title' => 'Shot', 'kind' => 'scene', 'action' => 'She acts.', 'seconds' => 3, 'shows' => $shows, 'depicts' => $depicts];

    $direction = SceneDirection::fromArray([
        'facts' => ['She aims the phone at the vessel.'],
        'events' => ['she aims the smartphone at the vessel', 'she turns her head', 'she lowers the smartphone'],
        'shots' => [$shot([1], [1, 3]), $shot([2], [5])],
    ], $this->project);

    expect($direction->problems())->toBe([
        'No shot depicts: she turns her head',
        'Shot 2 shows fact 2, which the facts do not have.',
        'Shot 2 depicts event 5, which the events do not have.',
    ]);
});

it('centres every directed shot on a subject, the one its kind has by itself when none fits', function () {
    $shot = fn(string $kind, string $subject) => ['title' => 'Shot', 'kind' => $kind, 'subject' => $subject, 'action' => 'She acts.', 'seconds' => 3];

    $direction = SceneDirection::fromArray(['shots' => [
        $shot('scene', ''),
        $shot('close-up', 'face'),
        $shot('close-up', 'nose'),
        $shot('insert', 'face'),
        $shot('medium', 'hands'),
    ]], $this->project);

    expect(array_column($direction->shots, 'subject'))->toBe(['body', 'face', 'hands', 'object', 'hands'])
        ->and(SceneDirection::fromArray($direction->toArray(), $this->project)->toArray())->toBe($direction->toArray())
        ->and($direction->withIssues(['The cut names a head turn the action does not show.'])->toArray()['issues'])->toBe(['The cut names a head turn the action does not show.']);
});

it('directs the story again with the issues of the review and the previous direction, and shows the shots seen before by code, kind and plan', function () {
    sequenceShot($this->project, 1, ShotKind::INSERT, ['group_key' => null, 'chosen_storyline' => ['storyline' => 'A wall-mounted no-camera sign on a metal wall panel.']]);
    $shot = sequenceShot($this->project, 2, ShotKind::SCENE, ['group_key' => null]);
    $previous = ['title' => 'No photo', 'shots' => [['title' => 'She raises the phone', 'cut' => 'Her head begins to turn.']]];

    $prompt = (new SceneDirector($shot, 'She turns her head and sees the sign.', [], ['The cut names a head turn the action does not show.'], $previous))->promptFor();

    expect($prompt)->toContain('- SH010, insert: A wall-mounted no-camera sign on a metal wall panel.')
        ->toContain('"cut": "Her head begins to turn."')
        ->toContain("A review found these issues in it:\n- The cut names a head turn the action does not show.")
        ->toContain('fix these issues and keep everything of the previous direction that was right')
        ->and((new SceneDirector($shot, 'She turns her head.'))->promptFor())->not->toContain('A review found');
});
