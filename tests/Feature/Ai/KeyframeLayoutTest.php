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
        ->and($prompt)->toContain('with the same kind of contact shadow under them as the others in <ref_image_0> have.')
        ->toContain('<driver> comes into the frame in their box, facing the guard; hands the permit out of the window. <driver> looks exactly like <ref_image_2>.')
        ->toContain('<visitor_car> stays exactly as it is.')
        ->toContain('The barrier arm is now open, pointing up.')
        ->toContain(App\Ai\Briefs\TextRules::NO_TEXT)
        ->not->toContain('lettering');
});

it('puts keyframe 1 right by moving who stands wrong and drawing who looks wrong from their picture', function () {
    $reading = KeyframeReading::fromArray(['cast' => [
        ['name' => 'Visitor car', 'box' => [450, 554, 574, 790], 'facing' => 'rear to the camera', 'pose' => 'stopped', 'placed_right' => false, 'target' => [635, 440, 800, 740], 'looks_right' => true, 'problem' => 'The car stands past the barrier.'],
        ['name' => 'Security guard', 'box' => [530, 171, 711, 277], 'facing' => 'side-on, facing the car', 'pose' => 'arms down', 'placed_right' => true, 'target' => [530, 171, 711, 277], 'looks_right' => false, 'problem' => 'He wears a yellow vest his picture does not have.'],
        ['name' => 'Visitor', 'box' => [500, 600, 560, 700], 'facing' => 'facing ahead', 'pose' => 'drives', 'placed_right' => true, 'target' => [500, 600, 560, 700], 'looks_right' => true, 'problem' => ''],
        ['name' => 'Someone else', 'box' => [100, 100, 300, 200], 'placed_right' => true, 'looks_right' => true],
    ]], ['Visitor car', 'Security guard', 'Visitor']);
    $keyframe = Keyframe::factory()->make(['position' => 1, 'description' => 'The car waits before the barrier.']);

    $prompt = KeyframeLayoutBrief::fix($keyframe, $reading, ['Security guard' => 1]);

    expect($reading->needsFixing())->toBeTrue()
        ->and($reading->cast)->toHaveCount(3)
        ->and($reading->layout()->member('Visitor car')['box'])->toBe([450, 554, 574, 790])
        ->and($prompt)->toContain('<visitor_car> moves to their new box, where the description puts them')
        ->toContain('<security_guard> stays where they stand, in the same pose, but looks exactly like <ref_image_1>')
        ->toContain('<visitor> stays exactly as it is.')
        ->toContain('"id":"visitor_car","from":"ref_image_0","src_bbox":[446,519,589,825],"tgt_bbox":[630,395,820,785]')
        ->toContain('"id":"security_guard","from":"ref_image_1","src_bbox":[0,0,1000,1000],"tgt_bbox":[530,171,711,277]')
        ->toContain('"id":"visitor","from":"ref_image_0","src_bbox":[500,600,560,700],"tgt_bbox":[500,600,560,700],"desc":"Visitor, unchanged","kind":"anchor"');
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
        Config::set(['services.replicate.token' => 'test-token', 'pipeline.keyframe_check' => false, 'pipeline.models.keyframe_layout' => 'black-forest-labs/flux-3-image']);

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

    it('stages the keyframe, sizes the cast by the depth of the place and keeps the layout for the next keyframe', function () {
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 2), $siblings);

        $second = $this->second->fresh();
        $guard = KeyframeLayout::fromArray($second->layout)->member('Security guard');

        expect($second->render())->not->toBeNull()
            ->and($second->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['The keyframe before, the image that is kept', 'The picture of Security guard'])
            // 156 high and 111 wide at 4 m; at 2 m twice that, with the feet where the stager put them.
            ->and($guard['box'])->toBe([588, 239, 900, 461])
            ->and($second->prompt)->toContain('"id":"security_guard","from":"ref_image_0","src_bbox":[550,122,730,267]')->toContain('<security_guard> looks exactly like <ref_image_1>');
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
            && count($request['input']['images']) === 2
            && str_starts_with($request['input']['images'][0], 'data:image/png;base64,')
            && str_contains($request['input']['prompt'], '"tgt_bbox":[579,206,937,494]'));
        KeyframeStager::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '- Security guard (person): in the image at [555, 139, 711, 250]')
            && $prompt->attachments->count() === 1);
    });

    it('draws keyframe 1 whole from the place\'s picture, moves who stands wrong and keeps where everyone stands', function () {
        Config::set('pipeline.models.keyframe', 'chat/image-model');
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        $this->first->clearMediaCollection(Keyframe::RENDERS);
        $this->first->forceFill(['layout' => null])->save();
        $gate = Element::factory()->for($this->project)->create(['name' => 'Entrance gate', 'type' => ElementType::PLACE]);
        $gate->addMediaFromString(layoutPng())->usingFileName('gate.png')->toMediaCollection(Element::REFERENCE);
        $this->first->elements()->attach([$this->guard->id, $gate->id]);
        KeyframeReader::fake([
            ['cast' => [['name' => 'Security guard', 'box' => ['top' => 300, 'left' => 600, 'bottom' => 450, 'right' => 680], 'facing' => 'facing the camera', 'pose' => 'arms down', 'placed_right' => false, 'target' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'looks_right' => true, 'problem' => 'He stands behind the booth.']]],
            ['cast' => [['name' => 'Security guard', 'box' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'facing' => 'side-on, facing the car', 'pose' => 'arms down', 'placed_right' => true, 'target' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'looks_right' => true, 'problem' => '']]],
        ]);
        $siblings = $this->shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $this->shot);

        app(KeyframePainter::class)->render($siblings->firstWhere('position', 1), $siblings);

        $first = $this->first->fresh();

        expect($first->renders())->toHaveCount(2)
            ->and($first->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['Keyframe 1 as drawn, the image that is put right'])
            ->and(KeyframeLayout::fromArray($first->layout)->member('Security guard')['box'])->toBe([555, 139, 711, 250]);
        Laravel\Ai\Image::assertGenerated(fn($prompt) => $prompt->contains('attached image is the place of this shot. Draw this same place')
            && $prompt->contains('Choose the camera and the framing for the action')
            && $prompt->attachments->count() === 2);
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'flux-3-image/predictions')
            && str_contains($request['input']['prompt'], '"id":"security_guard","from":"ref_image_0","src_bbox":[296,588,468,692],"tgt_bbox":[550,122,730,267]'));
        KeyframeStager::assertNeverPrompted();
    });

    it('draws an option for keyframe 1 without reading it, and reads and puts right the one chosen before the others are drawn', function () {
        Config::set('pipeline.models.keyframe', 'chat/image-model');
        Laravel\Ai\Image::fake(fn() => base64_encode(layoutPng()));
        Illuminate\Support\Facades\Queue::fake([App\Jobs\DrawKeyframe::class]);
        $this->first->clearMediaCollection(Keyframe::RENDERS);
        $this->first->forceFill(['layout' => null])->save();
        $this->first->elements()->attach($this->guard);
        KeyframeReader::fake([['cast' => [['name' => 'Security guard', 'box' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'facing' => 'facing the camera', 'pose' => 'arms down', 'placed_right' => true, 'target' => ['top' => 555, 'left' => 139, 'bottom' => 711, 'right' => 250], 'looks_right' => true, 'problem' => '']]]]);
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
