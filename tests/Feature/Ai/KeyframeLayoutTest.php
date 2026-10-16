<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeReader;
use App\Ai\Agents\KeyframeStager;
use App\Ai\Briefs\KeyframeLayoutBrief;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\DepthGrid;
use App\Support\Images\DepthSizing;
use App\Support\Images\PlaceDepth;
use App\Support\Shots\KeyframeLayout;
use App\Support\Shots\KeyframeReading;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function layoutPng(): string
{
    $image = imagecreatetruecolor(9, 16);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('keeps the image and who stays, moves who moves out of it and names every box by what it holds', function () {
    $before = new KeyframeLayout([
        ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'facing the camera', 'pose' => 'arms down', 'changed' => true],
        ['name' => 'Visitor car', 'box' => [650, 330, 920, 800], 'facing' => 'rear to the camera', 'pose' => 'stopped', 'changed' => true],
    ]);
    $layout = new KeyframeLayout([
        ['name' => 'Security guard', 'box' => [600, 290, 780, 410], 'facing' => 'side-on, facing the driver window on the right', 'pose' => 'holds out an open, empty hand', 'changed' => true],
        ['name' => 'Visitor car', 'box' => [650, 330, 920, 800], 'facing' => 'rear to the camera', 'pose' => 'stopped', 'changed' => false],
        ['name' => 'Driver', 'box' => [700, 500, 800, 560], 'facing' => 'facing the guard', 'pose' => 'hands the permit out of the window', 'changed' => true],
    ], [['thing' => 'barrier arm', 'state' => 'open, pointing up', 'box' => [430, 220, 760, 300]]]);
    $keyframe = Keyframe::factory()->make(['position' => 3, 'description' => 'The guard walks to the driver door.']);

    $rows = KeyframeLayoutBrief::rows($layout, $before, ['Security guard' => 1, 'Driver' => 2]);
    $prompt = KeyframeLayoutBrief::for($keyframe, $layout, $before, ['Security guard' => 1, 'Driver' => 2]);

    expect($rows)->toBe([
        ['id' => 'frame', 'from' => 'ref_image_0', 'src_bbox' => [0, 0, 1000, 1000], 'tgt_bbox' => [0, 0, 1000, 1000], 'desc' => 'the image before this keyframe: the place and everything in it that does not change', 'kind' => 'anchor'],
        // Moved out of the image before, from where he stood, so he keeps his look and light.
        ['id' => 'security_guard', 'from' => 'ref_image_0', 'src_bbox' => [550, 122, 730, 267], 'tgt_bbox' => [595, 272, 802, 428], 'desc' => 'Security guard, side-on, facing the driver window on the right, holds out an open, empty hand', 'kind' => 'move'],
        ['id' => 'visitor_car', 'from' => 'ref_image_0', 'src_bbox' => [650, 330, 920, 800], 'tgt_bbox' => [650, 330, 920, 800], 'desc' => 'Visitor car, unchanged', 'kind' => 'anchor'],
        // New in this keyframe: from his picture.
        ['id' => 'driver', 'from' => 'ref_image_2', 'src_bbox' => [0, 0, 1000, 1000], 'tgt_bbox' => [700, 500, 800, 560], 'desc' => 'Driver, facing the guard, hands the permit out of the window', 'kind' => 'move'],
        ['id' => 'barrier_arm', 'from' => null, 'src_bbox' => null, 'tgt_bbox' => [430, 220, 760, 300], 'desc' => 'the barrier arm, now open, pointing up', 'kind' => 'new'],
    ])
        ->and($prompt)->toContain('<security_guard> moves to their new box, side-on, facing the driver window on the right; holds out an open, empty hand. Where <security_guard> stood before, the place shows as it is behind them, empty, with no shadow left on the floor. <security_guard> looks exactly like <ref_image_1>.')
        ->and($prompt)->toContain('The feet of <security_guard> touch the floor at 780 of the frame height, on the 0 to 1000 grid, with the same kind of contact shadow under them as the others in <ref_image_0> have; their box reaches lower only to hold that shadow.')
        ->toContain('<driver> comes into the frame in their box, facing the guard; hands the permit out of the window. <driver> looks exactly like <ref_image_2>.')
        ->toContain('<visitor_car> stays exactly as it is.')
        ->toContain('The barrier arm is now open, pointing up.')
        ->toContain(App\Ai\Briefs\TextRules::NO_TEXT)
        ->not->toContain('lettering');
});

it('reads who stands wrong or looks wrong in keyframe 1 as notes, and keeps where everyone stands', function () {
    $reading = KeyframeReading::fromArray(['cast' => [
        ['name' => 'Visitor car', 'box' => [450, 554, 574, 790], 'facing' => 'rear to the camera', 'pose' => 'stopped', 'placed_right' => false, 'looks_right' => true, 'problem' => 'The car stands past the barrier.'],
        ['name' => 'Security guard', 'box' => [530, 171, 711, 277], 'facing' => 'side-on, facing the car', 'pose' => 'arms down', 'placed_right' => true, 'looks_right' => false, 'problem' => ''],
        ['name' => 'Visitor', 'box' => [500, 600, 560, 700], 'facing' => 'facing ahead', 'pose' => 'drives', 'placed_right' => true, 'looks_right' => true, 'problem' => ''],
        ['name' => 'Someone else', 'box' => [100, 100, 300, 200], 'placed_right' => true, 'looks_right' => true],
    ]], ['Visitor car', 'Security guard', 'Visitor']);

    expect($reading->cast)->toHaveCount(3)
        ->and($reading->problems())->toBe(['The car stands past the barrier.', 'Security guard does not look like their picture.'])
        ->and($reading->layout()->member('Visitor car')['box'])->toBe([450, 554, 574, 790]);
});

it('has the plan, the staging and the reading describe a hand about to receive something as empty', function () {
    $shot = Shot::factory()->create();
    $keyframe = Keyframe::factory()->make(['position' => 1, 'description' => 'The guard holds out his hand.']);
    $guard = Element::factory()->make(['name' => 'Security guard', 'type' => ElementType::PERSON]);

    expect((string) (new App\Ai\Agents\StorylineWriter($shot))->instructions())->toContain(App\Ai\Briefs\HandRules::RECEIVING)
        ->and((string) (new KeyframeStager($keyframe, [$guard], new KeyframeLayout()))->instructions())->toContain(App\Ai\Briefs\HandRules::RECEIVING)
        ->and((string) (new KeyframeReader($keyframe, [$guard]))->instructions())->toContain(App\Ai\Briefs\HandRules::RECEIVING);
});

it('reads only boxes that fit the grid and have a size', function () {
    expect(KeyframeLayout::box(['top' => 100, 'left' => 200, 'bottom' => 400, 'right' => 300]))->toBe([100, 200, 400, 300])
        ->and(KeyframeLayout::box([100, 200, 1400, -20]))->toBeNull()
        ->and(KeyframeLayout::box([100, 200, 105, 300]))->toBeNull()
        ->and(KeyframeLayout::box('far left'))->toBeNull();
});

it('draws someone smaller further from the camera, in proportion to the depth at their feet', function () {
    // Two rows of floor: 4 m away at the top half, 2 m at the bottom half.
    $depth = new DepthGrid(2, 2, [4.0, 4.0, 2.0, 2.0]);

    // Seen at 2 m, 200 high; now at 4 m, so half as high and wide, feet kept where they were put.
    expect(DepthSizing::fit([200, 400, 400, 500], [700, 100, 900, 200], $depth))->toBe([300, 425, 400, 475]);
});

describe('drawing on the image before', function () {
    beforeEach(function () {
        Storage::fake(Disk::TENANT->value);
        Config::set(['services.replicate.token' => 'test-token', 'pipeline.keyframe_check' => false, 'pipeline.models.keyframe_layout' => 'black-forest-labs/flux-3-image', 'pipeline.keyframes.layout.draws_keyframes' => true]);

        $this->project = Project::factory()->create();
        $this->shot = Shot::factory()->for($this->project)->create(['storyline' => ['keyframes' => [
            ['title' => 'Waiting', 'description' => 'The guard stands on the island.'],
            ['title' => 'At the door', 'description' => 'The guard walks to the driver door and reaches out a hand.'],
        ]]]);
        $this->guard = Element::factory()->for($this->project)->create(['name' => 'Security guard', 'type' => ElementType::PERSON]);
        $this->guard->addMediaFromString(layoutPng())->usingFileName('guard.png')->toMediaCollection(Element::REFERENCE);
        $this->first = Keyframe::factory()->for($this->shot)->create(['position' => 1, 'status' => KeyframeStatus::READY, 'layout' => ['cast' => [
            ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'facing the camera', 'pose' => 'arms down', 'changed' => true],
        ]]]);
        $this->first->addMediaFromString(layoutPng())->usingFileName('first.png')->toMediaCollection(Keyframe::RENDERS);
        $this->second = Keyframe::factory()->for($this->shot)->create(['position' => 2, 'title' => 'At the door', 'description' => 'The guard walks to the driver door and reaches out a hand.']);
        $this->second->elements()->attach($this->guard);

        // The depth is measured on keyframe 1. Ten rows of floor: the island, up to 80% down the frame, is 4 m away; the driver door below it 2 m, so the guard comes out twice as large.
        $this->first->fresh()->render()->setCustomProperty(PlaceDepth::DEPTH, (new DepthGrid(1, 10, [4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 4.0, 2.0, 2.0]))->toArray())->save();

        KeyframeStager::fake([[
            'cast' => [['name' => 'Security guard', 'box' => ['top' => 700, 'left' => 300, 'bottom' => 900, 'right' => 400], 'facing' => 'side-on, facing the driver window', 'pose' => 'reaches out an empty hand', 'changed' => true]],
            'changes' => [],
        ]]);
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-3-image/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png']),
            'replicate.delivery/*' => Http::response(layoutPng()),
        ]);
    });

    it('stages the keyframe, sizes the cast by the depth of the place, draws it on the plate and keeps the layout for the next keyframe', function () {
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        $second = $this->second->fresh();
        $guard = KeyframeLayout::fromArray($second->layout)->member('Security guard');

        expect($second->render())->not->toBeNull()
            ->and($second->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['The place without people, from this camera, the image that is kept', 'The keyframe before, where the people and changed things come from', 'The picture of Security guard'])
            // Keyframe 1 has nobody in it, so it is the plate itself.
            ->and($this->shot->fresh()->getFirstMedia(App\Models\Shot::PLATE)->getCustomProperty(App\Models\Shot::PLATE_FROM))->toBe($this->first->fresh()->render()->id)
            // 156 high and 111 wide at 4 m; at 2 m twice that, with the feet where the stager put them.
            ->and($guard['box'])->toBe([588, 239, 900, 461])
            ->and($second->prompt)->toContain('"id":"security_guard","from":"ref_image_1","src_bbox":[550,122,730,267]')->toContain('<security_guard> looks exactly like <ref_image_2>');
        Http::assertSentCount(2);
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
            && count($request['input']['images']) === 3
            && str_contains($request['input']['prompt'], '<ref_image_0> is the place of this shot without people')
            && ! str_contains($request['input']['prompt'], '_behind')
            && str_starts_with($request['input']['images'][0], 'data:image/png;base64,')
            && str_contains($request['input']['prompt'], '"tgt_bbox":[579,206,937,494]'));
        KeyframeStager::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '- Security guard (person): in the image at [555, 139, 711, 250]')
            && $prompt->attachments->count() === 1);
    });

    it('checks a fixed keyframe again and notes what the fix got wrong', function () {
        Config::set('pipeline.keyframe_check', true);
        $this->second->addMediaFromString(layoutPng())->usingFileName('second.png')->toMediaCollection(Keyframe::RENDERS);
        $this->second->forceFill(['layout' => $this->first->layout])->save();
        App\Ai\Agents\KeyframeFixLocator::fake([['fixes' => [['problem' => 'The hand is empty.', 'box' => ['top' => 600, 'left' => 100, 'bottom' => 700, 'right' => 300], 'member' => 'Security guard', 'looks' => false, 'shows' => 'the hand holds the permit', 'camera' => false]]]]);
        App\Ai\Agents\KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => [['category' => 'person', 'change' => 'The guard\'s wrist bends the wrong way.', 'severity' => 'high']]]);
        $second = $this->second->fresh()->load(['media', 'elements.media'])->setRelation('shot', $this->shot);

        $repaired = app(KeyframePainter::class)->repair($second, ['The hand is empty.']);

        expect($repaired->fresh()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['The guard\'s wrist bends the wrong way.']);
    });

    it('throws a plate away whose background moved from keyframe 1, tries once more and goes on without one', function () {
        $this->first->elements()->attach($this->guard->id);
        $drift = Mockery::mock(App\Support\Images\BackgroundDrift::class);
        $drift->shouldReceive('measure')->twice()->andReturn(['stillness' => 0.01, 'aligned' => 0.03, 'moved' => true]);
        app()->instance(App\Support\Images\BackgroundDrift::class, $drift);

        expect(app(App\Ai\LayoutPainter::class)->plate($this->second->fresh()))->toBeNull()
            ->and($this->shot->fresh()->getMedia(App\Models\Shot::PLATE))->toBeEmpty();
        Http::assertSentCount(4);
    });

    it('makes the plate once from keyframe 1 with its people taken out, keeps it, and makes it again when keyframe 1 changes', function () {
        $gate = Element::factory()->for($this->project)->create(['name' => 'Entrance gate', 'type' => ElementType::PLACE]);
        $gate->addMediaFromString(layoutPng())->usingFileName('gate.png')->toMediaCollection(Element::REFERENCE);
        $this->first->elements()->attach([$this->guard->id, $gate->id]);
        $painter = app(App\Ai\LayoutPainter::class);
        $plates = fn() => collect(Http::recorded())->map(fn(array $pair) => $pair[0])->filter(fn(Request $request) => str_ends_with($request->url(), 'predictions') && str_contains($request['input']['prompt'], 'Take every person out'));

        $painter->plate($this->second->fresh());
        $painter->plate($this->second->fresh());

        $plate = $this->shot->fresh()->getFirstMedia(App\Models\Shot::PLATE);
        expect($plates())->toHaveCount(1)
            ->and($plates()->first()['input']['images'])->toHaveCount(1)
            ->and($plates()->first()['input']['prompt'])->toContain('"id":"security_guard","from":"ref_image_0","src_bbox":[550,122,730,267],"tgt_bbox":null')
            ->not->toContain('ref_image_1')
            ->and($plate->getCustomProperty(App\Models\Shot::PLATE_FROM))->toBe($this->first->fresh()->render()->id);

        $this->first->addMediaFromString(layoutPng())->usingFileName('first-again.png')->toMediaCollection(Keyframe::RENDERS);
        $this->first->forceFill(['render_id' => $this->first->fresh()->renders()->last()->id])->save();
        $painter->plate($this->second->fresh());

        expect($plates())->toHaveCount(2)
            ->and($this->shot->fresh()->getMedia(App\Models\Shot::PLATE))->toHaveCount(1);
    });

    it('takes out who left since the keyframe before when there is no plate', function () {
        $before = new KeyframeLayout([
            ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'right', 'pose' => 'arms down', 'changed' => true],
            ['name' => 'Visitor', 'box' => [500, 600, 800, 700], 'facing' => 'left', 'pose' => 'waits', 'changed' => true],
        ]);
        $stays = new KeyframeLayout([['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'right', 'pose' => 'waves', 'changed' => true]]);
        $keyframe = Keyframe::factory()->make(['position' => 2, 'description' => 'The visitor has gone.']);

        expect(KeyframeLayoutBrief::for($keyframe, $stays, $before, []))
            ->toContain('<visitor> leaves the frame and is no longer in this keyframe.')
            ->toContain('"id":"visitor","from":"ref_image_0","src_bbox":[491,585,836,715],"tgt_bbox":null,"desc":"Visitor, removed","kind":"move"');
    });

    it('draws a later keyframe on the plate, with the cast and what changed earlier moved out of the keyframe before', function () {
        $before = new KeyframeLayout([
            ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'right', 'pose' => 'arms down', 'changed' => true],
            ['name' => 'Visitor', 'box' => [500, 600, 800, 700], 'facing' => 'left', 'pose' => 'waits', 'changed' => true],
            ['name' => 'Black backpack', 'box' => [700, 750, 800, 850], 'facing' => 'front', 'pose' => 'on the floor', 'changed' => false],
            ['name' => 'Permit', 'box' => [600, 300, 650, 340], 'facing' => 'front', 'pose' => 'on the desk', 'changed' => false],
        ]);
        $layout = new KeyframeLayout([
            ['name' => 'Security guard', 'box' => [555, 139, 711, 250], 'facing' => 'right', 'pose' => 'arms down', 'changed' => false],
            ['name' => 'Visitor', 'box' => [520, 400, 820, 500], 'facing' => 'right', 'pose' => 'walks to the gate', 'changed' => true],
            ['name' => 'Black backpack', 'box' => [700, 750, 800, 850], 'facing' => 'front', 'pose' => 'on the floor', 'changed' => false],
            ['name' => 'Driver', 'box' => [500, 800, 700, 900], 'facing' => 'left', 'pose' => 'steps out', 'changed' => true],
        ], [['thing' => 'gate', 'state' => 'open', 'box' => [300, 400, 600, 600]]]);
        $onPlate = ['Black backpack' => [700, 750, 800, 850], 'Permit' => [600, 300, 650, 340]];
        $carried = [['thing' => 'barrier arm', 'state' => 'open, pointing up', 'box' => [430, 220, 760, 300]]];
        $keyframe = Keyframe::factory()->make(['position' => 3, 'description' => 'The visitor walks to the gate.']);

        $rows = KeyframeLayoutBrief::plateRows($layout, $before, $onPlate, $carried, ['Driver' => 2]);
        $prompt = KeyframeLayoutBrief::onPlate($keyframe, $layout, $before, $onPlate, $carried, ['Driver' => 2]);

        expect($rows)->toBe([
            ['id' => 'frame', 'from' => 'ref_image_0', 'src_bbox' => [0, 0, 1000, 1000], 'tgt_bbox' => [0, 0, 1000, 1000], 'desc' => 'the place without people: everything that does not change', 'kind' => 'anchor'],
            // The permit was picked up: it is taken out of the place.
            ['id' => 'permit_plate', 'from' => 'ref_image_0', 'src_bbox' => [599, 294, 656, 346], 'tgt_bbox' => null, 'desc' => 'Permit, taken out of the place', 'kind' => 'move'],
            // An earlier keyframe opened the barrier; the plate still shows it closed.
            ['id' => 'barrier_arm', 'from' => 'ref_image_1', 'src_bbox' => [430, 220, 760, 300], 'tgt_bbox' => [430, 220, 760, 300], 'desc' => 'the barrier arm, still open, pointing up', 'kind' => 'move'],
            // From back to front: who stands lower in the frame is nearer and drawn last.
            ['id' => 'driver', 'from' => 'ref_image_2', 'src_bbox' => [0, 0, 1000, 1000], 'tgt_bbox' => [500, 800, 700, 900], 'desc' => 'Driver, left, steps out', 'kind' => 'move'],
            ['id' => 'security_guard', 'from' => 'ref_image_1', 'src_bbox' => [550, 122, 730, 267], 'tgt_bbox' => [550, 122, 730, 267], 'desc' => 'Security guard, unchanged', 'kind' => 'move'],
            ['id' => 'black_backpack', 'from' => 'ref_image_0', 'src_bbox' => [700, 750, 800, 850], 'tgt_bbox' => [700, 750, 800, 850], 'desc' => 'Black backpack, unchanged', 'kind' => 'anchor'],
            ['id' => 'visitor', 'from' => 'ref_image_1', 'src_bbox' => [491, 585, 836, 715], 'tgt_bbox' => [511, 385, 856, 515], 'desc' => 'Visitor, right, walks to the gate', 'kind' => 'move'],
            ['id' => 'gate', 'from' => null, 'src_bbox' => null, 'tgt_bbox' => [300, 400, 600, 600], 'desc' => 'the gate, now open', 'kind' => 'new'],
        ])
            ->and($prompt)->toContain('<ref_image_0> is the place of this shot without people')
            ->toContain('<security_guard> stays where they stand, exactly as in <ref_image_1>.')
            ->toContain('<black_backpack> stays exactly as it is.')
            ->toContain('<permit> is no longer there.')
            ->toContain('The barrier arm stays open, pointing up, exactly as in <ref_image_1>.')
            ->toContain('The gate is now open.')
            ->toContain('<driver> looks exactly like <ref_image_2>.')
            ->toContain('The feet of <visitor> touch the floor at 820 of the frame height, on the 0 to 1000 grid')
            ->not->toContain('_behind');
    });

    it('says who is in front where boxes overlap, by who stands lower in the frame', function () {
        $layout = new KeyframeLayout([
            ['name' => 'Black backpack', 'box' => [609, 542, 786, 804], 'facing' => 'front', 'pose' => 'on the floor', 'changed' => false],
            ['name' => 'Helmeted visitor', 'box' => [219, 653, 745, 987], 'facing' => 'right', 'pose' => 'walks right', 'changed' => true],
            ['name' => 'Security guard', 'box' => [200, 100, 600, 200], 'facing' => 'right', 'pose' => 'waits', 'changed' => false],
        ]);
        $keyframe = Keyframe::factory()->make(['position' => 5, 'description' => 'She walks past the backpack.']);

        expect(KeyframeLayoutBrief::onPlate($keyframe, $layout, $layout, ['Black backpack' => [609, 542, 786, 804]], [], []))
            ->toContain('<black_backpack> is in front of <helmeted_visitor>, nearer to the camera: <helmeted_visitor> stays in its spot and is partly hidden behind <black_backpack>.')
            ->not->toContain('<security_guard> is in front')
            ->and(KeyframeLayoutBrief::for($keyframe, $layout, $layout, []))->toContain('<black_backpack> is in front of <helmeted_visitor>');
    });

    it('lets the layout model pick the ratio and search first, unless the shot ratio and no grounding are set', function () {
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);
        $sent = fn() => collect(Http::recorded())->map(fn(array $pair) => $pair[0])->filter(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions'))->last()['input'];

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        expect($sent()['aspect_ratio'])->toBe('auto')
            ->and($sent())->not->toHaveKey('grounding');

        Config::set(['pipeline.keyframes.layout.fixed_ratio' => true, 'pipeline.keyframes.layout.grounding' => false]);
        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        expect($sent()['aspect_ratio'])->toBe($this->shot->aspectRatio()->value)
            ->and($sent()['grounding'])->toBeFalse();
    });

    it('draws keyframe 1 whole from the place\'s picture, notes who stands wrong without redrawing and keeps where everyone stands', function () {
        Config::set('pipeline.models.keyframe', 'chat/image-model');
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        $this->first->clearMediaCollection(Keyframe::RENDERS);
        $this->first->forceFill(['layout' => null])->save();
        $gate = Element::factory()->for($this->project)->create(['name' => 'Entrance gate', 'type' => ElementType::PLACE]);
        $gate->addMediaFromString(layoutPng())->usingFileName('gate.png')->toMediaCollection(Element::REFERENCE);
        $this->first->elements()->attach([$this->guard->id, $gate->id]);
        KeyframeReader::fake([
            ['cast' => [['name' => 'Security guard', 'box' => ['top' => 300, 'left' => 600, 'bottom' => 450, 'right' => 680], 'facing' => 'facing the camera', 'pose' => 'arms down', 'placed_right' => false, 'looks_right' => true, 'problem' => 'He stands behind the booth.']]],
        ]);
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 1), $siblings);

        $first = $this->first->fresh();

        expect($first->renders())->toHaveCount(1)
            ->and($first->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['He stands behind the booth.'])
            ->and(KeyframeLayout::fromArray($first->layout)->member('Security guard')['box'])->toBe([300, 600, 450, 680]);
        Laravel\Ai\Image::assertGenerated(fn($prompt) => $prompt->contains('attached image is the place of this shot. Draw this same place')
            && $prompt->contains('Choose the camera and the framing for the action')
            && $prompt->attachments->count() === 2);
        Http::assertNotSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions'));
        KeyframeStager::assertNeverPrompted();
    });

    it('draws an option for keyframe 1 without reading it, and reads the one chosen before the others are drawn', function () {
        Config::set('pipeline.models.keyframe', 'chat/image-model');
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        Illuminate\Support\Facades\Queue::fake([App\Jobs\DrawKeyframe::class]);
        $this->first->clearMediaCollection(Keyframe::RENDERS);
        $this->first->forceFill(['layout' => null])->save();
        $this->first->elements()->attach($this->guard);
        KeyframeReader::fake([['cast' => [['name' => 'Security guard', 'box' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'facing' => 'facing the camera', 'pose' => 'arms down', 'placed_right' => true, 'looks_right' => true, 'problem' => '']]]]);
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        $option = app(KeyframePainter::class)->render($siblings->firstWhere('position', 1), $siblings, choose: false);

        expect($this->first->fresh()->layout)->toBeNull();
        KeyframeReader::assertNeverPrompted();

        $this->first->forceFill(['render_id' => $option->id, 'status' => KeyframeStatus::READY])->save();
        (new App\Jobs\GenerateRemainingKeyframes($this->shot->fresh(), elementsDrawn: true))->handle(app(KeyframePainter::class), app(App\Support\Shots\KeyframeChain::class));

        expect(KeyframeLayout::fromArray($this->first->fresh()->layout)->member('Security guard')['box'])->toBe([555, 139, 711, 250])
            ->and($this->first->fresh()->status)->toBe(KeyframeStatus::READY);
        Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\DrawKeyframe::class, fn($job) => $job->keyframe->position === 2);
    });

    it('draws later keyframes whole with the keyframe model unless the layout model is set to, and reads where everyone stands', function () {
        Config::set(['pipeline.keyframes.layout.draws_keyframes' => false, 'pipeline.models.keyframe' => 'chat/image-model', 'pipeline.keyframes.drift.enabled' => false]);
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        KeyframeReader::fake([['cast' => [['name' => 'Security guard', 'box' => ['top' => 600, 'left' => 300, 'bottom' => 900, 'right' => 420], 'facing' => 'side-on', 'pose' => 'reaches out', 'placed_right' => true, 'looks_right' => true, 'problem' => '']]]]);
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        $second = $this->second->fresh();
        expect($second->render()->getCustomProperty(Keyframe::SENT)['model'])->toBe('chat/image-model')
            ->and(KeyframeLayout::fromArray($second->layout)->member('Security guard')['box'])->toBe([600, 300, 900, 420]);
        Http::assertNotSent(fn(Request $request) => str_contains($request->url(), 'flux-3-image'));
        KeyframeStager::assertNeverPrompted();
    });

    it('draws a keyframe from its brief when there is no image to draw it on', function () {
        Config::set(['services.replicate.token' => null, 'pipeline.models.keyframe' => 'chat/image-model']);
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        expect($this->second->fresh()->layout)->toBeNull();
        Http::assertNothingSent();
        KeyframeStager::assertNeverPrompted();
    });
});
