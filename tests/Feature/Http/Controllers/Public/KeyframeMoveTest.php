<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeReader;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\ChangeCastMember;
use App\Jobs\DrawKeyframe;
use App\Jobs\MoveCastMember;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\DepthGrid;
use App\Support\Images\PlaceDepth;
use App\Support\Shots\KeyframeChain;
use App\Support\Shots\KeyframeLayout;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

function movePng(): string
{
    $image = imagecreatetruecolor(9, 16);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Config::set(['services.replicate.token' => 'test-token', 'pipeline.keyframe_check' => false, 'pipeline.models.keyframe_layout' => 'black-forest-labs/flux-3-image']);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
    $this->shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
    $this->guard = Element::factory()->for($this->project)->create(['name' => 'Security guard', 'type' => ElementType::PERSON]);
    $this->first = Keyframe::factory()->for($this->shot)->create(['position' => 1, 'status' => KeyframeStatus::READY, 'layout' => ['cast' => [
        ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'side-on, facing the car', 'pose' => 'arms down', 'changed' => true],
        ['name' => 'Visitor car', 'box' => [650, 330, 920, 800], 'facing' => 'rear to the camera', 'pose' => 'stopped', 'changed' => true],
    ]]]);
    $this->first->addMediaFromString(movePng())->usingFileName('first.png')->toMediaCollection(Keyframe::RENDERS);
    $this->first->elements()->attach($this->guard);
    $this->second = Keyframe::factory()->for($this->shot)->create(['position' => 2, 'status' => KeyframeStatus::READY]);
    $this->second->addMediaFromString(movePng())->usingFileName('second.png')->toMediaCollection(Keyframe::RENDERS);
    app(KeyframeChain::class)->link($this->shot);
});

it('offers the layout and the move for a drawn keyframe of a scene', function () {
    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $this->shot]))
        ->assertInertia(fn($page) => $page
            ->where('keyframes.0.layout.0', ['name' => 'Security guard', 'box' => [555, 139, 711, 250]])
            ->where('keyframes.0.links.move', route('public.shots.keyframes.move', [$this->project, $this->shot, $this->first]))
            ->where('keyframes.1.layout', []));
});

it('reads where the cast stands when a keyframe has no layout yet', function () {
    KeyframeReader::fake([['cast' => [['name' => 'Security guard', 'box' => ['top' => 500, 'left' => 100, 'bottom' => 700, 'right' => 200], 'facing' => 'facing the camera', 'pose' => 'arms down', 'placed_right' => true, 'looks_right' => true, 'problem' => '']]]]);
    $this->second->elements()->attach($this->guard);
    $this->guard->addMediaFromString(movePng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.layout', [$this->project, $this->shot, $this->second]))
        ->assertSessionHasNoErrors();

    expect(KeyframeLayout::fromArray($this->second->fresh()->layout)->member('Security guard')['box'])->toBe([500, 100, 700, 200]);
});

it('moves one of the cast to the box the director dragged them to', function () {
    Queue::fake();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $this->shot, $this->first]), ['name' => 'Security guard', 'box' => [600, 290, 780, 410], 'following' => true])
        ->assertSessionHasNoErrors();

    expect($this->first->fresh()->status)->toBe(KeyframeStatus::DRAWING);
    Queue::assertPushed(MoveCastMember::class, fn(MoveCastMember $job) => $job->name === 'Security guard' && $job->box === [600, 290, 780, 410] && $job->following && $job->version === $this->first->fresh()->draw_version);
});

it('refuses a move of someone who is not in the keyframe, or while it is drawn', function () {
    Queue::fake();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $this->shot, $this->first]), ['name' => 'Nobody', 'box' => [600, 290, 780, 410]])
        ->assertSessionHasErrors('move');

    $this->first->forceFill(['status' => KeyframeStatus::DRAWING])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $this->shot, $this->first]), ['name' => 'Security guard', 'box' => [600, 290, 780, 410]])
        ->assertSessionHasErrors('move');

    Queue::assertNothingPushed();
});

it('draws the move on the keyframe itself, sized by the depth, and the keyframes after it when asked', function () {
    Queue::fake([DrawKeyframe::class]);
    Http::fake([
        'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
        'replicate.delivery/*' => Http::response(movePng()),
    ]);
    // The island is 4 m away; the road 80% down the frame and lower 2 m, so the guard comes out twice as large there.
    $this->first->render()->setCustomProperty(PlaceDepth::DEPTH, (new DepthGrid(1, 10, [4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 2.0, 2.0]))->toArray())->save();
    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();

    (new MoveCastMember($this->first->fresh(), 1, 'Security guard', [600, 290, 900, 400], following: true))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    $first = $this->first->fresh();

    expect($first->status)->toBe(KeyframeStatus::READY)
        ->and($first->renders())->toHaveCount(2)
        ->and(KeyframeLayout::fromArray($first->layout)->member('Security guard')['box'])->toBe([588, 234, 900, 456])
        ->and(KeyframeLayout::fromArray($first->layout)->member('Visitor car')['box'])->toBe([650, 330, 920, 800])
        ->and($this->second->fresh()->status)->toBe(KeyframeStatus::DRAWING);
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
        && count($request['input']['images']) === 1
        && str_contains($request['input']['prompt'], '"id":"security_guard","from":"ref_image_0","src_bbox":[550,122,717,267],"tgt_bbox":[579,201,912,489]')
        && ! str_contains($request['input']['prompt'], 'soft shadow')
        && str_contains($request['input']['prompt'], '<visitor_car> stays exactly as it is.'));
    Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->is($this->second));
});

describe('past the edge of the frame', function () {
    beforeEach(function () {
        Queue::fake([DrawKeyframe::class]);
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
            'replicate.delivery/*' => Http::response(movePng()),
        ]);
        $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();
    });

    it('takes someone out of the shot when they are dragged wholly past the edge', function () {
        (new MoveCastMember($this->first->fresh(), 1, 'Security guard', [555, -400, 711, -289]))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

        expect(KeyframeLayout::fromArray($this->first->fresh()->layout)->member('Security guard'))->toBeNull()
            ->and(KeyframeLayout::fromArray($this->first->fresh()->layout)->member('Visitor car'))->not->toBeNull();
        // He is taken out, and the layout model fills in where he stood.
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'predictions') && str_contains($request['input']['prompt'], '"id":"security_guard","from":"ref_image_0","src_bbox":[550,122,717,267],"tgt_bbox":null')
            && str_contains($request['input']['prompt'], '<security_guard> leaves the frame'));
    });

    it('leaves someone cut off by the edge when they are dragged partly past it, and keeps only what shows', function () {
        (new MoveCastMember($this->first->fresh(), 1, 'Security guard', [555, -50, 711, 61]))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

        expect(KeyframeLayout::fromArray($this->first->fresh()->layout)->member('Security guard')['box'])->toBe([555, 0, 711, 61]);
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'predictions') && str_contains($request['input']['prompt'], '"from":"ref_image_0","src_bbox":[550,122,717,267],"tgt_bbox":[550,0,717,70]')
            && str_contains($request['input']['prompt'], 'only the part of them inside the box shows'));
    });

    it('draws someone who was cut off from their picture when they come back in, so they are whole', function () {
        $this->guard->addMediaFromString(movePng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);
        $this->first->forceFill(['layout' => ['cast' => [['name' => 'Security guard', 'box' => [555, 0, 711, 61], 'facing' => 'facing the car', 'pose' => 'arms down', 'changed' => true]]]])->save();

        (new MoveCastMember($this->first->fresh(), 1, 'Security guard', [555, 139, 711, 250]))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'predictions') && count($request['input']['images']) === 2
            && str_contains($request['input']['prompt'], '"id":"security_guard","from":"ref_image_1","src_bbox":[0,0,1000,1000],"tgt_bbox":[555,139,711,250]')
            && str_contains($request['input']['prompt'], 'looks exactly like <ref_image_1>, the whole of them'));
    });
});

it('changes one of the cast where they stand, keeping everyone else', function () {
    Queue::fake([DrawKeyframe::class]);
    Http::fake([
        'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
        'replicate.delivery/*' => Http::response(movePng()),
    ]);
    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();

    (new ChangeCastMember($this->first->fresh(), 1, 'Security guard', 'He raises his left hand.'))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    $first = $this->first->fresh();

    expect($first->status)->toBe(KeyframeStatus::READY)
        ->and($first->renders())->toHaveCount(2)
        ->and($first->render()->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('He raises his left hand.');
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'predictions')
        && str_contains($request['input']['prompt'], '"id":"security_guard","from":"ref_image_0","src_bbox":[550,122,717,267],"tgt_bbox":[550,122,717,267],"desc":"Security guard, He raises his left hand"')
        && str_contains($request['input']['prompt'], '<visitor_car> stays exactly as it is.'));
});

describe('a change with staging', function () {
    beforeEach(function () {
        Queue::fake([DrawKeyframe::class]);
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
            'replicate.delivery/*' => Http::response(movePng()),
        ]);
        $this->guard->addMediaFromString(movePng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);
        $this->first->restage(new KeyframeStaging('The guard watches the car', '', [
            KeyframeStaging::member(['name' => 'Security guard', 'stands' => 'left', 'faces' => 'away-right', 'looks_at' => 'the Visitor car', 'holds' => 'the permit', 'hand' => 'left']),
        ]))->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();
        $this->change = fn() => (new ChangeCastMember($this->first->fresh(), 1, 'Security guard', 'He turns toward the car.'))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));
        $this->prompts = fn() => collect(Http::recorded())->map(fn(array $pair) => $pair[0])->filter(fn(Request $request) => str_ends_with($request->url(), 'predictions'))->map(fn(Request $request) => $request['input'])->values();
    });

    it('draws the changed one again from their picture in their own box, so their old pose does not hold them back', function () {
        ($this->change)();

        $sent = ($this->prompts)();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['images'])->toHaveCount(2)
            ->and($sent[0]['prompt'])->toContain('"id":"security_guard","from":"ref_image_1","src_bbox":[0,0,1000,1000],"tgt_bbox":[555,139,711,250]')
            ->toContain('<security_guard> stays where they stand and is drawn again in their box, the whole of them, looking exactly like <ref_image_1>, in this pose: He turns toward the car.')
            ->toContain('<visitor_car> stays exactly as it is.')
            ->and($this->first->fresh()->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['This keyframe, the image that is kept', 'The picture of Security guard'])
            ->and($this->first->fresh()->status)->toBe(KeyframeStatus::READY);
    });
});

it('fixes what the check found only where it is, from the picture of whoever it is about', function () {
    Queue::fake([DrawKeyframe::class]);
    Http::fake([
        'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
        'replicate.delivery/*' => Http::response(movePng()),
    ]);
    App\Ai\Agents\KeyframeFixLocator::fake([['fixes' => [
        ['problem' => 'The lettering disappeared from the front of his hard hat.', 'box' => ['top' => 555, 'left' => 150, 'bottom' => 600, 'right' => 240], 'member' => 'security guard', 'looks' => true, 'shows' => 'His hard hat with the lettering on the front, as in his picture.'],
        ['problem' => 'Nothing to find.', 'box' => ['top' => 0, 'left' => 0, 'bottom' => 2, 'right' => 2], 'member' => '', 'shows' => 'Nothing.'],
    ]]]);
    $this->guard->addMediaFromString(movePng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);
    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();

    (new App\Jobs\FixKeyframe($this->first->fresh(), 1, ['The lettering disappeared from the front of his hard hat.']))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    $first = $this->first->fresh();

    expect($first->status)->toBe(KeyframeStatus::READY)
        ->and($first->render()->getCustomProperty(Keyframe::TWEAK_FROM_CHECK))->toBeTrue()
        ->and(KeyframeLayout::fromArray($first->layout)->member('Security guard')['box'])->toBe([555, 139, 711, 250]);
    App\Ai\Agents\KeyframeFixLocator::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '- Security guard, at [555, 139, 711, 250]')
        && str_contains($prompt->prompt, '- The lettering disappeared from the front of his hard hat.'));
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
        && count($request['input']['images']) === 2
        && str_contains($request['input']['prompt'], '"id":"security_guard_fix","from":"ref_image_0","src_bbox":[555,150,600,240],"tgt_bbox":[555,150,600,240]')
        && str_contains($request['input']['prompt'], 'exactly from <ref_image_1>, the picture of Security guard')
        && str_contains($request['input']['prompt'], '<visitor_car> stays exactly as it is.')
        && ! str_contains($request['input']['prompt'], '"id":"security_guard","from"')
        && ! str_contains($request['input']['prompt'], 'Nothing to find'));
});

it('leaves a changed camera as a note to draw again for, and fixes only what a box can fix', function () {
    Queue::fake([DrawKeyframe::class]);
    Http::fake([
        'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
        'replicate.delivery/*' => Http::response(movePng()),
    ]);
    $camera = 'The framing changes from keyframe 1: the ship and the quay shift in size and position.';
    $framing = ['problem' => $camera, 'box' => ['top' => 0, 'left' => 0, 'bottom' => 1000, 'right' => 1000], 'member' => '', 'looks' => false, 'shows' => 'The scene framed as in keyframe 1.', 'camera' => true];
    App\Ai\Agents\KeyframeFixLocator::fake([
        ['fixes' => [
            ['problem' => 'He does not turn his head.', 'box' => ['top' => 555, 'left' => 139, 'bottom' => 620, 'right' => 250], 'member' => 'Security guard', 'looks' => false, 'shows' => 'His head turned to the left.', 'camera' => false],
            $framing,
        ]],
        ['fixes' => [$framing]],
    ]);
    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();

    (new App\Jobs\FixKeyframe($this->first->fresh(), 1, ['He does not turn his head.', $camera]))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    $render = $this->first->fresh()->render();
    expect($this->first->fresh()->renders())->toHaveCount(2)
        ->and($render->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('He does not turn his head.')
        ->and($render->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe([$camera]);
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'predictions') && ! str_contains($request['input']['prompt'], 'framing changes'));

    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 2])->save();
    (new App\Jobs\FixKeyframe($this->first->fresh(), 2, [$camera]))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    expect($this->first->fresh()->renders())->toHaveCount(2)
        ->and($this->first->fresh()->status)->toBe(KeyframeStatus::READY);
    Http::assertSentCount(2);
});

it('fixes a pose without the picture of whoever it is about, which would undo it', function () {
    Queue::fake([DrawKeyframe::class]);
    Http::fake([
        'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
        'replicate.delivery/*' => Http::response(movePng()),
    ]);
    App\Ai\Agents\KeyframeFixLocator::fake([['fixes' => [
        ['problem' => 'Both arms are already through the vest.', 'box' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'member' => 'Security guard', 'looks' => false, 'shows' => 'One arm through the vest, the other still outside it.'],
    ]]]);
    $this->guard->addMediaFromString(movePng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);
    $this->first->forceFill(['status' => KeyframeStatus::DRAWING, 'draw_version' => 1])->save();

    (new App\Jobs\FixKeyframe($this->first->fresh(), 1, ['Both arms are already through the vest.']))->handle(app(App\Ai\KeyframePainter::class), app(KeyframeChain::class));

    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
        && count($request['input']['images']) === 1
        && str_contains($request['input']['prompt'], 'Security guard looks as they do now; only their pose')
        && ! str_contains($request['input']['prompt'], 'the picture of Security guard'));
    App\Ai\Agents\KeyframeFixLocator::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'looks: true when the problem is how they look compared with their picture'));
});
