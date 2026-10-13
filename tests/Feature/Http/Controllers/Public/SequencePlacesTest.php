<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateSequencePlaceOption;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\SequencePlaces;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function sequencePng(): string
{
    $image = imagecreatetruecolor(9, 16);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * The fire sequence agreed in the chat of its first shot.
 *
 * @return array<string, mixed>
 */
function fireSequenceReply(): array
{
    return ['reply' => 'Four shots; first we choose the corridor, for shots 1 to 3.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [
        ['takeaway' => 'See the danger', 'kind' => 'close-up', 'idea' => 'Smoke under the meeting-room door.', 'setting' => 'new_place', 'same_place_as' => 0, 'cast' => ['Meeting-room door'], 'place' => 'Meeting-room corridor'],
        ['takeaway' => 'Stop and think', 'kind' => 'scene', 'idea' => 'She lowers the phone beside the door.', 'setting' => 'new_place', 'same_place_as' => 0, 'cast' => ['Visitor', 'Host', 'Meeting-room door'], 'place' => 'meeting-room corridor'],
        ['takeaway' => 'Get safe', 'kind' => 'close-up', 'idea' => 'Her hand pockets the phone.', 'setting' => 'continues', 'same_place_as' => 0, 'cast' => ['Visitor'], 'place' => ''],
        ['takeaway' => 'Then call', 'kind' => 'scene', 'idea' => 'Outside she calls 112.', 'setting' => 'new_place', 'same_place_as' => 0, 'cast' => ['Visitor', 'Smartphone'], 'place' => 'Assembly point outside'],
    ]];
}

/**
 * Runs the job that makes the empty place from a chosen master, which a faked bus only records.
 */
function makeMasters(): void
{
    if (Bus::getFacadeRoot() instanceof Illuminate\Support\Testing\Fakes\BusFake) {
        Bus::dispatched(App\Jobs\MakeSequenceMaster::class)->each(fn(App\Jobs\MakeSequenceMaster $job) => app()->call([$job, 'handle']));
    }
}

/**
 * The first shot of the agreed fire sequence, with the options of its first place drawn.
 */
function fireSequence(Project $project): Shot
{
    Bus::fake();
    PlanDirector::fake([fireSequenceReply()]);
    $shot = Shot::factory()->for($project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

    test()->actingAs(test()->director, 'director')->postJson(route('public.shots.plan.chat', [$project, $shot]), ['message' => 'yes, those four']);

    $shot->refresh();

    foreach ([1, 2] as $n) {
        $shot->addMediaFromString(sequencePng())->usingFileName("corridor-{$n}.png")->withCustomProperties(['place' => 'meeting-room-corridor'])->toMediaCollection(Shot::SEQUENCE_PLACE_OPTIONS);
    }

    SequencePlaces::finish($shot->id, 'meeting-room-corridor');

    return $shot->refresh();
}

it('keeps the places of an agreed sequence on its first shot and draws the first one once the director agrees to the overview', function () {
    Bus::fake();
    PlanDirector::fake([fireSequenceReply(), ['reply' => 'I draw the places now.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [], 'draw' => true]]);
    $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes, those four'])
        ->assertJsonPath('reload', true);

    $shots = $this->project->shots()->get();

    expect(app(SequencePlaces::class)->places($shots[0]))->toEqual([
        ['key' => 'meeting-room-corridor', 'name' => 'Meeting-room corridor', 'shots' => ['SH010', 'SH020'], 'ideas' => ['Smoke under the meeting-room door.', 'She lowers the phone beside the door.'], 'elements' => ['Meeting-room door', 'Visitor', 'Host']],
        ['key' => 'assembly-point-outside', 'name' => 'Assembly point outside', 'shots' => ['SH040'], 'ideas' => ['Outside she calls 112.'], 'elements' => ['Visitor', 'Smartphone']],
    ])
        ->and($shots->map->sequencePlaceKey()->all())->toBe(['meeting-room-corridor', 'meeting-room-corridor', null, 'assembly-point-outside'])
        // Nothing waits for a later shot; the cut-in continues the shot before.
        ->and($shots[0]->storyline)->not->toHaveKey('setting_from')
        ->and($shots[2]->storyline['setting_from']['shot_id'])->toBe($shots[1]->id)
        // The overview says what each shot shows; no second list of the same shots beside it.
        ->and($response->json('messages.1.overview'))->not->toBeEmpty()
        ->and($response->json('messages.1.changes') ?? [])->not->toContain('SH010 (Close-up) uses Meeting-room door; plays in Meeting-room corridor, chosen first')
        // Nothing is drawn before the director agrees to the overview.
        ->and($shots[0]->fresh()->storyline)->not->toHaveKey('sequence_places_drawing')
        ->and($shots[0]->fresh()->storyline[SequencePlaces::PLACES_WAIT])->toBeTrue();
    Bus::assertNothingBatched();

    $agreed = actingAs($this->director, 'director')->postJson(route('public.shots.plan.chat', [$this->project, $shots[0]]), ['message' => 'yes, draw the places']);

    // The page reloads, so the places being drawn show in the chat right away.
    expect($agreed->json('reload'))->toBeTrue()
        ->and($shots[0]->fresh()->storyline['sequence_places_drawing'])->toBe('meeting-room-corridor')
        ->and($shots[0]->fresh()->storyline)->not->toHaveKey(SequencePlaces::PLACES_WAIT)
        // Agreeing to the overview agrees to every shot in it: their plans are drawn without asking again.
        ->and($shots->every(fn(Shot $member) => App\Support\Shots\SequenceOverview::agreed($member->fresh())))->toBeTrue();

    // While it is drawn, the chat shows it.
    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shots[0]]))
        ->assertInertia(fn($page) => $page->where('shot.sequencePlaceDrawing.name', 'Meeting-room corridor')->where('shot.sequencePlace', null));
    Bus::assertBatched(fn($batch) => $batch->jobs->count() === max(1, (int) config('pipeline.keyframes.plate_options'))
        && $batch->jobs->every(fn(GenerateSequencePlaceOption $job) => $job->place === 'meeting-room-corridor'));
});

it('draws a place of the sequence as its master: wide, with the cast where they stand at the start', function () {
    Image::fake(fn() => base64_encode(sequencePng()));
    $shot = fireSequence($this->project);
    $place = app(SequencePlaces::class)->places($shot)[0];

    $option = app(KeyframePainter::class)->paintSequencePlaceOption($shot->load('project'), $place, 0);

    expect($option->collection_name)->toBe(Shot::SEQUENCE_PLACE_OPTIONS)
        ->and($option->getCustomProperty('place'))->toBe('meeting-room-corridor');
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Draw Meeting-room corridor, wide, with the people and the things that move in it where they stand at the start of the first shot here')
        && $prompt->contains('so where everyone stands, which way they face and which side of the frame they are on is decided by this picture')
        && $prompt->contains('- SH010: Smoke under the meeting-room door.'));
});

it('gives the master the pictures of its people and of what moves, such as a truck, people first', function () {
    Image::fake(fn() => base64_encode(sequencePng()));
    $shot = fireSequence($this->project);
    $truck = App\Models\Element::factory()->object()->for($this->project)->create(['name' => 'Delivery truck']);
    $truck->addMediaFromString(sequencePng())->usingFileName('truck.png')->toMediaCollection(App\Models\Element::REFERENCE);
    $guard = App\Models\Element::factory()->for($this->project)->create(['name' => 'Gate guard', 'type' => 'person']);
    $guard->addMediaFromString(sequencePng())->usingFileName('guard.png')->toMediaCollection(App\Models\Element::REFERENCE);
    $place = [...app(SequencePlaces::class)->places($shot)[0], 'elements' => ['Delivery truck', 'Gate guard']];

    $option = app(KeyframePainter::class)->paintSequencePlaceOption($shot->load('project'), $place, 0);

    expect($option->getCustomProperty(App\Models\Keyframe::SENT)['images'])->toBe(['Picture of Gate guard', 'Picture of Delivery truck']);
});

it('has the place chosen in the chat first, then the next place, and says so', function () {
    $shot = fireSequence($this->project);

    expect(collect($shot->plan_chat)->last()['text'])->toBe('The options for Meeting-room corridor are ready, for SH010, SH020. Click the one you like or tell me which one, or ask me for more.');

    PlanDirector::fake([['reply' => 'The second one it is.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [], 'choice' => ['action' => 'choose', 'option' => 2]]]);

    $reply = 'messages.' . count($shot->plan_chat) + 1;

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'the second one'])
        ->assertJsonPath("{$reply}.changes", ['Place 2 chosen for Meeting-room corridor'])
        ->assertJsonPath("{$reply}.chosenPlace.name", 'Meeting-room corridor')
        // Another place still waits to be chosen, so the chat does not go on by itself yet.
        ->assertJsonPath('continue', false)
        ->assertJsonMissingPath("{$reply}.chosen_place");
    makeMasters();

    $places = app(SequencePlaces::class);
    $shot->refresh();

    expect($places->chosen($shot, 'meeting-room-corridor'))->not->toBeNull()
        ->and($places->current($shot)['key'])->toBe('assembly-point-outside')
        ->and(collect($shot->plan_chat)->last()['text'])->toBe('The second one it is. Meeting-room corridor is chosen. Now Assembly point outside, for SH040; I am drawing it.');
    PlanDirector::assertPrompted(fn($prompt) => count($prompt->attachments) === 2 && str_contains((string) $prompt->agent->instructions(), 'Give each shot its place'));
    Bus::assertBatched(fn($batch) => $batch->jobs->first()->place === 'assembly-point-outside');
});

it('chooses a place by clicking it in the chat, and every shot there draws its own view of it', function () {
    $shot = fireSequence($this->project);
    $second = $this->project->shots()->where('position', 2)->firstOrFail();
    $option = $shot->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.sequencePlace.name', 'Meeting-room corridor')->has('shot.sequencePlace.options', 2));

    expect($second->settingWaitsFor())->toBe('Meeting-room corridor of the sequence');

    actingAs($this->director, 'director')
        ->post(route('public.shots.sequence-places.choose', [$this->project, $shot]), ['place' => $option->id])
        ->assertRedirect(route('public.shots.view', [$this->project, $shot]));
    makeMasters();

    $setting = app(KeyframePainter::class)->settingFor($second->fresh());

    // The chosen place stays in the conversation.
    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.planChat.' . (count($shot->fresh()->plan_chat) - 1) . '.chosenPlace.name', 'Meeting-room corridor'));

    // The place is there; it now waits for the shot before it in that place, to see what that one leaves behind.
    expect(collect($shot->fresh()->plan_chat)->pluck('text'))->toContain('Place 1 for Meeting-room corridor.')
        ->and($second->fresh()->settingWaitsFor())->toBe('the keyframes of SH010')
        ->and($setting['label'])->toBe('Meeting-room corridor, the place chosen for this sequence of shots')
        // A full scene there plays in the chosen place as it is; the close-up draws its own view of it instead of the end of the shot before.
        ->and($second->fresh()->forceFill(['storyline' => [...$second->storyline, 'framing' => ['size' => 'full', 'spot' => '']]])->inheritedPlate()?->getCustomProperty('place'))->toBe('meeting-room-corridor')
        ->and($shot->fresh()->kindOrScene())->toBe(ShotKind::CLOSE_UP)
        ->and($shot->fresh()->settingFrom())->toBeNull();
});

it('drops a setting from an earlier agreement of the sequence when it is agreed again with places', function () {
    Bus::fake();
    $later = Shot::factory()->for($this->project)->create(['position' => 2]);
    $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => [], 'setting_from' => ['shot_id' => $later->id, 'keyframe' => 0, 'sequence' => true]]]);
    PlanDirector::fake([fireSequenceReply()]);

    actingAs($this->director, 'director')->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes, those four']);

    expect($shot->fresh()->storyline)->not->toHaveKey('setting_from')
        ->and($shot->fresh()->sequencePlaceKey())->toBe('meeting-room-corridor');
});

it('never lets a wider shot continue a close-up: it plays in the close-up\'s place instead', function () {
    Bus::fake();
    PlanDirector::fake([['reply' => 'Fire first, then her.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [
        ['takeaway' => 'See the fire', 'kind' => 'close-up', 'idea' => 'The bin burns.', 'setting' => 'new_place', 'same_place_as' => 0, 'cast' => ['Burning waste bin'], 'place' => 'Fire spot'],
        ['takeaway' => 'Step away', 'kind' => 'scene', 'idea' => 'She steps back from the bin.', 'setting' => 'continues', 'same_place_as' => 0, 'cast' => ['Visitor', 'Burning waste bin'], 'place' => ''],
    ]]]);
    $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'kind' => ShotKind::CLOSE_UP, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);

    actingAs($this->director, 'director')->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'yes']);

    $scene = $this->project->shots()->where('position', 2)->firstOrFail();

    expect($scene->sequencePlaceKey())->toBe('fire-spot')
        ->and($scene->storyline)->not->toHaveKey('setting_from')
        ->and(app(SequencePlaces::class)->places($shot->fresh())[0]['shots'])->toBe(['SH010', 'SH020']);
});

it('draws a scene that was set to start from a close-up in that close-up\'s chosen place', function () {
    $closeUp = fireSequence($this->project);
    $option = $closeUp->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first();
    app(SequencePlaces::class)->choose($closeUp, $option, say: false);
    makeMasters();
    // Planned before this rule, like SH120: a full scene taking its setting from keyframe 2 of the close-up.
    $scene = Shot::factory()->for($this->project)->create(['position' => 5, 'group_key' => $closeUp->group_key, 'kind' => ShotKind::SCENE, 'storyline' => ['framing' => ['size' => 'full', 'spot' => ''], 'keyframes' => [], 'setting_from' => ['shot_id' => $closeUp->id, 'keyframe' => 2]]]);

    expect($scene->settingFrom())->toBeNull()
        ->and($scene->sequencePlaceKey())->toBe('meeting-room-corridor')
        ->and($scene->inheritedPlate()?->getCustomProperty('place'))->toBe('meeting-room-corridor');
});

it('goes on with the shot by itself once the last place is chosen, without a message from the director', function () {
    $shot = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::STORYLINE_READY, 'plan_chat' => [['role' => 'assistant', 'text' => 'Safe spot is chosen.']], 'storyline' => ['keyframes' => []]]);
    PlanDirector::fake([['reply' => 'Now the keyframes of SH110: 1. The bin burns. 2. The flames rise. Do these work?', 'stage' => 'keyframes', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [],
        'rules_check' => ['places' => 'the fire spot', 'must_read' => 'none', 'rules' => [], 'story' => 'the bin burns; the flames rise.', 'sequence' => []]]]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['continue' => true])
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.1.text', 'Now the keyframes of SH110: 1. The bin burns. 2. The flames rise. Do these work?');

    expect(collect($shot->fresh()->plan_chat)->pluck('role')->all())->toBe(['assistant', 'assistant']);
    PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Every place of the sequence is chosen now. Go on with this shot'));
});

it('still needs a message when the chat is not asked to go on by itself', function () {
    $shot = Shot::factory()->for($this->project)->create(['position' => 1]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), [])
        ->assertJsonValidationErrors('message');
});

it('maps a chosen place once, and gives every shot there the map and its own view of it', function () {
    $closeUp = fireSequence($this->project);
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'framing' => ['size' => 'full', 'spot' => ''], 'sequence_view' => 'from the safe spot looking back along the corridor; the door is behind her, off the left of the frame']);
    App\Ai\Agents\PlaceMapper::fake([['map' => 'The meeting-room door is on the left, the exit at the far end on the right.', 'things' => [], 'routes' => []]]);

    app(SequencePlaces::class)->choose($closeUp, $closeUp->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);

    makeMasters();

    Bus::assertDispatched(App\Jobs\MakeSequenceMaster::class, fn($job) => $job->place === 'meeting-room-corridor');

    $scene = $scene->fresh();
    $setting = app(KeyframePainter::class)->settingFor($scene);

    expect($setting['label'])->toBe('Meeting-room corridor, the place chosen for this sequence of shots. Where things stand in it: The meeting-room door is on the left, the exit at the far end on the right. This shot shows: from the safe spot looking back along the corridor; the door is behind her, off the left of the frame; keep every fixed thing where the place has it, also what is out of this view')
        // Another part of the place: drawn as its own view, not the chosen picture as it is.
        ->and($scene->inheritedPlate())->toBeNull()
        ->and((string) (new PlanDirector($scene->load('project'), []))->promptFor('ok'))->toContain('this shot draws its own view of it: from the safe spot looking back');
    App\Ai\Agents\PlaceMapper::assertPrompted(fn($prompt) => count($prompt->attachments) === 1 && str_contains($prompt->prompt, 'Things with a name here: Meeting-room door, Visitor, Host'));
});

it('asks again when two shots in a row are no cut: the same camera on the same place', function (array $first, array $second, bool $broken) {
    $shot = fn(array $overrides) => ['takeaway' => 'T', 'idea' => 'I', 'same_place_as' => 0, 'cast' => [], 'setting' => 'new_place', 'place' => 'Fire spot', 'view' => '', ...$overrides];

    expect(PlanDirector::sequenceProblems(['shots' => [$shot($first), $shot($second)]]) !== [])->toBe($broken);
})->with([
    'same place, kind and view' => [['kind' => 'scene'], ['kind' => 'scene'], true],
    'a wider shot continuing the one before' => [['kind' => 'scene', 'place' => 'Quay'], ['kind' => 'scene', 'setting' => 'continues', 'place' => ''], true],
    'another part of the place' => [['kind' => 'scene', 'view' => 'close on the bin'], ['kind' => 'scene', 'view' => 'from the safe spot'], false],
    'a close-up cut in' => [['kind' => 'scene'], ['kind' => 'close-up', 'setting' => 'continues', 'place' => ''], false],
    'another place' => [['kind' => 'scene'], ['kind' => 'scene', 'place' => 'Assembly point'], false],
]);

it('tells the review where the shot before ended and which part of the place each shows', function () {
    $closeUp = fireSequence($this->project);
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $closeUp->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'sequence_view' => 'close on the door', 'keyframes' => [['title' => 'Smoke', 'description' => 'Smoke curls under the door.']]]);
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'sequence_view' => 'from the safe spot; the door is off the left of the frame']);

    $prompt = (new App\Ai\Agents\ShotReviewer($scene->fresh()->load('project'), collect()))->promptFor();

    expect($prompt)->toContain('This shot is part of a sequence. The shot before, SH010, ends with: Smoke curls under the door. It plays in Meeting-room corridor, showing close on the door.')
        ->toContain('This shot: It plays in Meeting-room corridor, showing from the safe spot; the door is off the left of the frame.')
        // Every shot of the sequence with what it shows, so what another shot shows is not asked of this one.
        ->toContain("The shots of the sequence:\n- SH010 (Close-up): Smoke\n- SH020 (Scene), this shot: She lowers the phone beside the door.\n- SH030 (Close-up): Her hand pockets the phone.")
        ->and((string) (new App\Ai\Agents\ShotReviewer($scene, collect()))->instructions())->toContain('a danger or goal out of the frame is not an issue when the shot before shows where it is')
        ->toContain('what another shot of the sequence shows, such as the permit in the close-up after this shot, is never an issue here');
});

it('leaves the place of its sequence when a new plan moves the shot to another place', function () {
    $closeUp = fireSequence($this->project);
    app(SequencePlaces::class)->choose($closeUp, $closeUp->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);
    makeMasters();
    App\Models\Element::factory()->for($this->project)->create(['name' => 'Meeting-room door', 'type' => 'object']);
    App\Models\Element::factory()->for($this->project)->create(['name' => 'Meeting-room corridor', 'type' => 'place']);
    App\Models\Element::factory()->for($this->project)->create(['name' => 'Plain hallway', 'type' => 'place']);
    $scene = $this->project->shots()->where('position', 2)->firstOrFail()->load('project');
    $plan = fn(string $place) => ['kind' => 'scene', 'storyline' => 'She walks.', 'seconds' => 4, 'keyframes' => [['title' => 'Walk', 'description' => 'She walks.', 'elements' => [$place]]]];

    App\Support\Shots\ShotPlan::apply($scene, $plan('Meeting-room corridor'));
    // The same place again keeps it.
    App\Support\Shots\ShotPlan::apply($scene->fresh()->load('project'), $plan('Meeting-room corridor'));

    expect($scene->fresh()->sequencePlaceKey())->toBe('meeting-room-corridor');

    App\Support\Shots\ShotPlan::apply($scene->fresh()->load('project'), $plan('Plain hallway'));

    expect($scene->fresh()->sequencePlaceKey())->toBeNull()
        ->and($scene->fresh()->sequenceView())->toBeNull()
        ->and(app(KeyframePainter::class)->settingFor($scene->fresh()))->toBeNull();
});

/**
 * A place with clear structure, as stripes one way or the other, so its camera can be compared.
 */
function stripedSequencePlace(bool $across): string
{
    $image = imagecreatetruecolor(144, 256);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 230, 230, 230));
    $dark = (int) imagecolorallocate($image, 30, 30, 30);

    // Upright stripes for the chosen place; another camera sees a dark top and a light bottom.
    if ($across) {
        for ($at = 0; $at < 144; $at += 18) {
            imagefilledrectangle($image, $at, 0, $at + 6, 255, $dark);
        }
    } else {
        imagefilledrectangle($image, 0, 0, 143, 127, $dark);
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('writes once what the camera of a shot with its own view sees, and draws its place from that', function () {
    $closeUp = fireSequence($this->project);
    app(SequencePlaces::class)->choose($closeUp, $closeUp->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);
    makeMasters();
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'sequence_view' => 'from the safe end looking back; the bin is behind them']);
    App\Ai\Agents\CameraBriefWriter::fake([['brief' => 'The camera stands at the safe end, looking back. The bin is far away on the right of the frame, small.']]);

    app(SequencePlaces::class)->writeCameraBrief($scene->fresh());
    app(SequencePlaces::class)->writeCameraBrief($scene->fresh());

    $label = app(KeyframePainter::class)->settingFor($scene->fresh())['label'];

    expect($label)->toContain("This shot's camera stands elsewhere: from the safe end looking back; the bin is behind them. What this camera sees: The camera stands at the safe end, looking back. The bin is far away on the right of the frame, small.")
        ->toContain('never copy the viewpoint of the attached picture');
    App\Ai\Agents\CameraBriefWriter::assertPromptedTimes(1);
    App\Ai\Agents\CameraBriefWriter::assertPrompted(fn($prompt) => count($prompt->attachments) === 1 && str_contains($prompt->prompt, "The other shot's view: from the safe end looking back"));
});

it('draws the place of a shot with its own view again when it kept the camera of the chosen place, and says so when it keeps doing that', function (bool $secondDifferent) {
    $closeUp = fireSequence($this->project);
    $chosen = $closeUp->addMediaFromString(stripedSequencePlace(across: true))->usingFileName('corridor.png')->withCustomProperties(['place' => 'meeting-room-corridor'])->toMediaCollection(Shot::SEQUENCE_PLACES);
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'framing' => ['size' => 'medium', 'spot' => 'The corridor.'], 'sequence_view' => 'from the safe end looking back', 'keyframes' => [['title' => 'Back', 'description' => 'She looks back.', 'elements' => []]]]);
    $drawn = 0;
    Image::fake(function () use (&$drawn, $secondDifferent) {
        return base64_encode(stripedSequencePlace(across: ++$drawn === 1 || ! $secondDifferent));
    });

    (new App\Jobs\GeneratePlateOption($scene->fresh(), 0))->handle(app(KeyframePainter::class), app(SequencePlaces::class));

    $options = $scene->fresh()->getMedia(Shot::PLATE_OPTIONS);

    expect($options)->toHaveCount(1)
        ->and($options->first()->getCustomProperty(App\Jobs\GeneratePlateOption::SAME_CAMERA))->toBe($secondDifferent ? null : true);
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The previous attempt copied the camera of the attached picture of the place.'));
})->with([
    'the second one turns the camera' => [true],
    'both keep the camera' => [false],
]);

it('gives a shot another view from the chat and draws new places from it, since a keyframe never moves the camera', function () {
    $closeUp = fireSequence($this->project);
    app(SequencePlaces::class)->choose($closeUp, $closeUp->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);
    makeMasters();
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'sequence_view' => 'from the safe end; the bin is off the left of the frame', SequencePlaces::CAMERA_BRIEF => ['for' => 'old', 'text' => 'Old brief.'], 'keyframes' => [['title' => 'Back', 'description' => 'She looks back.', 'elements' => []]]]);
    $scene->addMediaFromString(sequencePng())->usingFileName('option.png')->toMediaCollection(Shot::PLATE_OPTIONS);
    Bus::fake();
    PlanDirector::fake([['reply' => 'The bin is part of the place and the camera stands at the bin, so redrawing keyframe 1 will not move it. I draw new places from the safe end.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'proposal' => null, 'shots' => [],
        'view' => 'from the safe end looking back; the burning bin is far behind them, small, on the right of the frame']]);

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $scene->fresh()]), ['message' => 'I want to see the bin far behind them']);

    $scene = $scene->fresh();

    expect($scene->sequenceView())->toBe('from the safe end looking back; the burning bin is far behind them, small, on the right of the frame')
        ->and($scene->storyline)->not->toHaveKey(SequencePlaces::CAMERA_BRIEF)
        ->and($scene->getMedia(Shot::PLATE_OPTIONS))->toBeEmpty()
        // Drawn as soon as the shot before in this place is drawn.
        ->and($scene->waitsToDraw())->toBeTrue()
        ->and($scene->waitsFor())->toBe('the keyframes of SH010')
        ->and(collect($response->json('messages'))->last()['changes'])->toContain('New view: from the safe end looking back; the burning bin is far behind them, small, on the right of the frame; new places are drawn from it');
    PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'So a change of a keyframe, place false, can never remove, move or reframe anything of the place'));
});

it('has the camera brief work out where things are before it follows the words of the view', function () {
    expect((string) (new App\Ai\Agents\CameraBriefWriter('Corridor', 'from the far end'))->instructions())
        ->toContain('When the view\'s own words about a thing do not fit this geometry, the geometry wins.');
});

/**
 * The fire sequence with its corridor chosen, and SH010 drawn with one keyframe.
 *
 * @return array{0: Shot, 1: Shot}
 */
function drawnFirstOfSequence(Project $project): array
{
    $first = fireSequence($project);
    app(SequencePlaces::class)->choose($first, $first->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);
    makeMasters();
    $first->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();
    $keyframe = App\Models\Keyframe::factory()->for($first)->create(['position' => 1]);
    $render = $keyframe->addMediaFromString(sequencePng())->usingFileName('last.png')->toMediaCollection(App\Models\Keyframe::RENDERS);
    $keyframe->forceFill(['render_id' => $render->id])->save();

    return [$first->fresh(), $project->shots()->where('position', 2)->firstOrFail()];
}

it('reads what a shot leaves behind in its place, and the next shot there waits for it, then draws it where it stands', function () {
    [$first, $second] = drawnFirstOfSequence($this->project);

    expect($second->settingWaitsFor())->toBe('what SH010 leaves behind');

    App\Ai\Agents\LeftBehindReader::fake([['things' => ['Site helicopter'], 'left' => 'The Site helicopter stands on the landing circle, its nose towards the superstructure.']]);
    (new App\Jobs\ReadLeftBehind($first))->handle(app(SequencePlaces::class));

    $second = $second->fresh();
    $setting = app(KeyframePainter::class)->settingFor($second);

    expect($second->settingWaitsFor())->toBeNull()
        ->and($setting['label'])->toContain('Since SH010, these stand in the place too and are drawn where they stand, as part of it: The Site helicopter stands on the landing circle, its nose towards the superstructure.')
        ->and($setting['left']['label'])->toBe('the last keyframe of SH010, the same place from another camera at the end of that shot')
        // Not in the chosen picture: a full scene there draws its own place.
        ->and($second->forceFill(['storyline' => [...$second->storyline, 'framing' => ['size' => 'full', 'spot' => '']]])->inheritedPlate())->toBeNull()
        ->and((string) (new PlanDirector($second->load('project'), []))->promptFor('ok'))->toContain('In this place now, left there by SH010: The Site helicopter stands on the landing circle');
    App\Ai\Agents\LeftBehindReader::assertPrompted(fn($prompt) => count($prompt->attachments) === 1);
});

it('tells a later shot already drawn when the shot before it ends differently now', function () {
    [$first, $second] = drawnFirstOfSequence($this->project);
    App\Ai\Agents\LeftBehindReader::fake([
        ['things' => ['Site helicopter'], 'left' => 'The Site helicopter stands on the landing circle.'],
        ['things' => ['Site helicopter'], 'left' => 'The Site helicopter stands beside the landing circle, near the rail.'],
    ]);
    (new App\Jobs\ReadLeftBehind($first))->handle(app(SequencePlaces::class));
    $drawn = App\Models\Keyframe::factory()->for($second)->create(['position' => 1]);
    $drawn->forceFill(['render_id' => $drawn->addMediaFromString(sequencePng())->usingFileName('k.png')->toMediaCollection(App\Models\Keyframe::RENDERS)->id])->save();

    // The last keyframe of SH010 is drawn again.
    $last = $first->keyframes()->firstOrFail();
    $last->forceFill(['render_id' => $last->addMediaFromString(sequencePng())->usingFileName('again.png')->toMediaCollection(App\Models\Keyframe::RENDERS)->id])->save();
    (new App\Jobs\ReadLeftBehind($first->fresh()))->handle(app(SequencePlaces::class));

    expect(collect($second->fresh()->plan_chat)->last()['text'])->toBe('SH010 ends differently now: The Site helicopter stands beside the landing circle, near the rail. This shot may no longer match it; ask me to draw it again if it does not.');
});

it('keeps the options there to choose from while more are drawn, and does not offer the extra ones once one was chosen', function () {
    $shot = fireSequence($this->project);

    app(SequencePlaces::class)->more($shot);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->has('shot.sequencePlace.options', 2)->where('shot.sequencePlaceDrawing.name', 'Meeting-room corridor')->where('shot.sequencePlaceDrawing.count', 3));

    // One of the round is there: only the others are shown as being drawn.
    $shot->addMediaFromString(sequencePng())->usingFileName('corridor-3.png')->withCustomProperties(['place' => 'meeting-room-corridor'])->toMediaCollection(Shot::SEQUENCE_PLACE_OPTIONS);

    expect(app(SequencePlaces::class)->drawingPlace($shot->fresh())['count'])->toBe(2);

    app(SequencePlaces::class)->choose($shot->fresh(), $shot->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first(), say: false);

    makeMasters();
    $said = count($shot->fresh()->plan_chat);
    SequencePlaces::finish($shot->id, 'meeting-room-corridor');

    expect($shot->fresh()->plan_chat)->toHaveCount($said);
});

/**
 * A grey place of 144 by 256, with a red person standing at x 60 to 80, y 100 to 180 when asked.
 */
function masterPng(bool $person): string
{
    $image = imagecreatetruecolor(144, 256);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 128, 128, 128));

    if ($person) {
        imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocate($image, 220, 40, 40));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function personMask(bool $alpha): string
{
    $image = imagecreatetruecolor(144, 256);
    imagesavealpha($image, $alpha);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, $alpha ? 127 : 0));
    imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('makes the empty place from the chosen master, reads where the cast stands and keeps the box around them', function () {
    Illuminate\Support\Facades\Config::set('services.replicate.token', 'test-token');
    $people = (string) Illuminate\Support\Facades\Config::get('pipeline.keyframes.composite.people_model');
    Illuminate\Support\Facades\Http::fake([
        'api.replicate.com/v1/predictions' => fn(Illuminate\Http\Client\Request $request) => Illuminate\Support\Facades\Http::response(['status' => 'succeeded', 'output' => ($request['version'] ?? null) === $people ? 'https://replicate.delivery/front.png' : 'https://replicate.delivery/person.png']),
        'replicate.delivery/front.png' => Illuminate\Support\Facades\Http::response(personMask(true)),
        'replicate.delivery/person.png' => Illuminate\Support\Facades\Http::response(personMask(false)),
    ]);
    App\Ai\Agents\MovingThingsJudge::fake(fn() => ['moving' => []]);
    App\Ai\Agents\BlockingReader::fake(fn() => ['blocking' => 'The Visitor stands in the middle of the frame, facing the door on the left.']);
    App\Ai\Agents\PlaceMapper::fake(fn() => ['map' => 'The door is on the left.', 'things' => [], 'routes' => []]);
    // The edit model takes the people out: the place without them.
    Image::fake(fn() => base64_encode(masterPng(false)));

    $shot = fireSequence($this->project);
    App\Models\Element::factory()->for($this->project)->create(['name' => 'Visitor', 'type' => 'person']);
    $master = $shot->addMediaFromString(masterPng(true))->usingFileName('master.png')->withCustomProperties(['place' => 'meeting-room-corridor'])->toMediaCollection(Shot::SEQUENCE_PLACE_OPTIONS);

    app(SequencePlaces::class)->choose($shot, $master, say: false);
    makeMasters();

    $places = app(SequencePlaces::class);
    $shot->refresh();
    $empty = $places->chosen($shot, 'meeting-room-corridor');
    $box = $empty->getCustomProperty(SequencePlaces::CAST_BOX);
    $red = fn(string $png, int $x, int $y) => ($image = imagecreatefromstring($png)) !== false ? imagecolorsforindex($image, imagecolorat($image, $x, $y))['red'] : 0;
    $pixel = $red(Storage::disk($empty->disk)->get($empty->getPathRelativeToRoot()), 70, 140);

    expect($places->master($shot, 'meeting-room-corridor'))->not->toBeNull()
        ->and($empty->getCustomProperty(SequencePlaces::MASTER))->not->toBeTrue()
        ->and($empty->getCustomProperty(SequencePlaces::BLOCKING))->toBe('The Visitor stands in the middle of the frame, facing the door on the left.')
        ->and($empty->getCustomProperty(SequencePlaces::MAP))->toBe('The door is on the left.')
        ->and($box['x'])->toEqualWithDelta(60 / 144, 0.05)
        ->and($box['height'])->toEqualWithDelta(80 / 256, 0.06)
        // The person is gone from the empty place.
        ->and($pixel)->toBeLessThan(160);
});

it('cuts a medium scene\'s place from the empty place around the cast, with the same cut of the master for keyframe 1', function () {
    $shot = fireSequence($this->project);
    $scene = $this->project->shots()->where('position', 2)->firstOrFail();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'framing' => ['size' => 'medium', 'spot' => ''], 'keyframes' => [['title' => 'Looks', 'description' => 'She looks at the door.']]]);
    $master = $shot->addMediaFromString(masterPng(true))->usingFileName('master.png')->withCustomProperties(['place' => 'meeting-room-corridor', SequencePlaces::MASTER => true])->toMediaCollection(Shot::SEQUENCE_PLACES);
    $shot->addMediaFromString(masterPng(false))->usingFileName('empty.png')->withCustomProperties(['place' => 'meeting-room-corridor', 'master_id' => $master->id, SequencePlaces::CAST_BOX => ['x' => 60 / 144, 'y' => 100 / 256, 'width' => 20 / 144, 'height' => 80 / 256]])->toMediaCollection(Shot::SEQUENCE_PLACES);
    $painter = app(KeyframePainter::class);

    $cut = $painter->masterPlate($scene->fresh()->load('project'));

    $plate = imagecreatefromstring($cut['plate']);
    $red = fn(string $png, int $x, int $y) => ($image = imagecreatefromstring($png)) !== false ? imagecolorsforindex($image, imagecolorat($image, $x, $y))['red'] : 0;

    // The same size as the place, closer around her: she fills the middle of the cut of the master, and the place has no one.
    expect([imagesx($plate), imagesy($plate)])->toBe([144, 256])
        ->and($red($cut['blocking'], 72, 128))->toBeGreaterThan(180)
        ->and($red($cut['blocking'], 72, 30))->toBeLessThan(160)
        ->and($red($cut['plate'], 72, 128))->toBeLessThan(160);

    // Drawing the shot: its place is the cut, chosen, nothing to choose; keyframe 1 gets the cut of the master to put her where she stands.
    Illuminate\Support\Facades\Config::set('pipeline.keyframes.start_with_plate', true);
    (new App\Jobs\GenerateKeyframes($scene->fresh()))->handle();
    $scene = $scene->fresh();
    $first = $scene->keyframes()->with(['media', 'elements'])->firstOrFail()->setRelation('shot', $scene);

    // Already without its people and what moves: nothing to get ready before things can be moved; a closer cut has its depth measured.
    Bus::assertDispatched(App\Jobs\MeasurePlaceDepth::class);
    expect($scene->getFirstMedia(Shot::PLATE)?->getCustomProperty(Shot::PLATE_EMPTIED_DONE))->toBeTrue()
        ->and($scene->getFirstMedia(Shot::PLATE)?->getCustomProperty(Shot::PLATE_CHOSEN))->toBeTrue()
        ->and($scene->getFirstMedia(Shot::BLOCKING))->not->toBeNull()
        ->and($painter->referencesFor($first, collect([$first]))->labels())->toContain('Where the cast stands, from the master');

    // A full shot is the whole empty place, so it keeps the depth measured on it once.
    $places = app(SequencePlaces::class);
    $places->chosen($shot->fresh(), 'meeting-room-corridor')->setCustomProperty(App\Support\Images\PlaceDepth::DEPTH, ['width' => 1, 'height' => 1, 'values' => [1.0]])->save();
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, 'framing' => ['size' => 'full', 'spot' => '']]);
    expect($painter->masterPlate($scene->fresh())['depth'])->toEqual(['width' => 1, 'height' => 1, 'values' => [1.0]]);

    // A shot that asked for its own camera draws its own place.
    $scene->updateStoredJson('storyline', fn(?array $storyline) => [...$storyline, KeyframePainter::OWN_CAMERA => true]);
    expect($painter->masterPlate($scene->fresh()))->toBeNull();
});

it('starts a shot cut from the master with the place as an earlier shot left it, such as the barrier up', function () {
    Image::fake(fn() => base64_encode(masterPng(false)));
    $shot = Shot::factory()->for($this->project)->create(['storyline' => ['keyframes' => [
        ['title' => 'Past', 'description' => 'The truck starts past the open barrier.', 'place_change' => 'The barrier arm is raised, leaving the lane open.', 'place_part' => 'barrier arm'],
        ['title' => 'Gone', 'description' => 'The truck leaves the frame.', 'place_change' => '', 'place_part' => ''],
    ]]])->load('project');
    $shot->addMediaFromString(masterPng(false))->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    $shot->addMediaFromString(masterPng(true))->usingFileName('blocking.png')->toMediaCollection(Shot::BLOCKING);
    $painter = app(KeyframePainter::class);

    $painter->ensurePlaceStates($shot, collect());

    expect($painter->placeAt($shot->fresh(), collect(), 1)?->collection_name)->toBe(Shot::PLACE_STATES);
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The barrier arm is raised, leaving the lane open.'));
    expect((string) (new App\Ai\Agents\StorylineWriter($shot))->instructions())->toContain('In keyframe 1 it is empty too, except for a shot that plays in the place of its sequence after an earlier shot there changed it');
});

it('shows in the chat what an agreed shot waits for while the empty place of its sequence is made', function () {
    $shot = fireSequence($this->project);
    $option = $shot->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->first();
    app(SequencePlaces::class)->choose($shot, $option, say: false);
    App\Support\Shots\SequenceOverview::agree($shot);

    PlanDirector::fake([['reply' => 'I draw it now.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [], 'proposal' => [
        'takeaway' => 'See the danger', 'kind' => 'close-up', 'storyline' => 'Smoke seeps under the door.', 'seconds' => 3, 'setting_from' => null,
        'keyframes' => [['title' => 'Smoke', 'description' => 'A thin wisp of smoke under the meeting-room door.', 'spatial' => '', 'elements' => []]],
    ]]]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot->fresh()]), ['message' => 'ok'])
        ->assertJsonPath('reload', true)
        ->assertJsonPath('waitingFor', $shot->fresh()->waitsFor())
        // The page keeps looking until the drawing shows, also when one reload gets lost.
        ->assertJsonPath('drawing', true);

    $waits = $shot->fresh()->waitsFor();

    expect($waits)->not->toBeNull();
    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.waitingFor', $waits)->where('shot.status', 'storyline-ready'));
});
