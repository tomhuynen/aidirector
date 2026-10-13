<?php

declare(strict_types=1);

use App\Ai\Agents\MovedPersonDescriber;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Jobs\MeasurePlaceDepth;
use App\Jobs\PoseMovedPerson;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\DepthGrid;
use App\Support\Images\PlaceDepth;
use App\Support\Images\PlaceObjects;
use App\Support\Images\TopLayerCutout;
use App\Support\Shots\FirstKeyframeChoice;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    // No cut-out or depth model unless a test fakes it: people are found by what differs from the place or a given mask.
    Config::set('services.replicate.token', null);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A grey place, optionally with a red "person" standing with their feet at the given point.
 */
function placePng(?array $person = null): string
{
    $image = imagecreatetruecolor(432, 768);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 128, 128, 128));
    imagefilledrectangle($image, 0, 500, 431, 767, (int) imagecolorallocate($image, 90, 110, 160));

    if ($person !== null) {
        [$feetX, $feetY] = $person;
        imagefilledrectangle($image, $feetX - 25, $feetY - 160, $feetX + 25, $feetY, (int) imagecolorallocate($image, 220, 40, 40));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A keyframe drawn on a chosen place, with a person whose feet are at (300, 600).
 */
function keyframeOnPlace(Project $project): Keyframe
{
    $shot = Shot::factory()->for($project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
    $shot->addMediaFromString(placePng())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'He looks up at the container and points at it.']);
    $render = $keyframe->addMediaFromString(placePng([300, 600]))->usingFileName('keyframe-1.png')->toMediaCollection(Keyframe::RENDERS);
    $keyframe->forceFill(['render_id' => $render->id])->save();

    return $keyframe->fresh();
}

function pixelAt(string $path, int $x, int $y): array
{
    $image = imagecreatefrompng($path);
    // A png with few colours comes back as a palette, where a pixel is an index into it.
    $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue']];
}

it('finds the person the director clicks by what differs from the place', function () {
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('box.x'))->toEqualWithDelta(275 / 432, 0.03)
        ->and($response->json('box.y'))->toEqualWithDelta(440 / 768, 0.03)
        ->and($response->json('cutout'))->toStartWith('data:image/png;base64,')
        ->and($response->json('overlay'))->toStartWith('data:image/png;base64,');

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $shot, $keyframe]), ['x' => 0.1, 'y' => 0.2])
        ->assertJsonValidationErrors('x');
});

it('moves the person at once, shows the place where they stood, and only makes the plan follow the image', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render();

    expect(pixelAt($moved->getPath(), 300, 540))->toBe([90, 110, 160])
        ->and(pixelAt($moved->getPath(), 100, 540)[0])->toBeGreaterThan(200)
        ->and($keyframe->fresh()->rendering)->toBeFalse()
        ->and($moved->getCustomProperty(Keyframe::MOVED_BOX)['x'] + $moved->getCustomProperty(Keyframe::MOVED_BOX)['width'] / 2)->toEqualWithDelta(100 / 432, 0.03);
    Queue::assertPushed(PoseMovedPerson::class, fn(PoseMovedPerson $job) => $job->redraw === false);
});

it('moves and then redraws it at the new spot when the director picks redraw, with its own shadow and light', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1, 'redraw' => true])
        ->assertSessionHasNoErrors();

    expect($keyframe->fresh()->rendering)->toBeTrue();
    Queue::assertPushed(PoseMovedPerson::class, fn(PoseMovedPerson $job) => $job->redraw && str_contains($job->instruction, 'their shadow and light for this spot'));
});

it('brings the shadow the redraw gives along with the redrawn person', function () {
    $keyframe = keyframeOnPlace($this->project);
    $box = app(TopLayerCutout::class)->select($keyframe->shot->getFirstMedia(Shot::PLATE)->getPath(), $keyframe->render()->getPath(), 300 / 432, 520 / 768)['box'];
    // The redraw gives the crop back with a shadow on the floor right of the feet: the floor, darker by the same factor.
    Image::fake(function (ImagePrompt $prompt) {
        $crop = imagecreatefromstring((string) Storage::disk('local')->get($prompt->attachments->first()->path));
        [$right, $bottom] = [0, 0];

        for ($x = 0; $x < imagesx($crop); $x++) {
            for ($y = 0; $y < imagesy($crop); $y++) {
                $colour = imagecolorsforindex($crop, imagecolorat($crop, $x, $y));

                if ($colour['red'] > 200 && $colour['green'] < 80) {
                    [$right, $bottom] = [max($right, $x), max($bottom, $y)];
                }
            }
        }

        imagefilledrectangle($crop, $right + 1, $bottom - 10, $right + 50, $bottom, (int) imagecolorallocate($crop, 45, 55, 80));
        ob_start();
        imagepng($crop);

        return base64_encode((string) ob_get_clean());
    });
    MovedPersonDescriber::fake([['description' => 'He stands in the lane.', 'storyline' => '', 'title' => 'Lane', 'warning' => '']]);

    (new PoseMovedPerson($keyframe, 'Redraw the person.', $box))->handle(app(KeyframePainter::class), app(TopLayerCutout::class), app(BackgroundDrift::class));

    $render = $keyframe->fresh()->render();

    // Found by what differs from the place, the shadow counts as part of him here, so both land a little to the left.
    expect(pixelAt($render->getPath(), 330, 596)[2])->toBeLessThan(120)
        ->and(pixelAt($render->getPath(), 285, 540)[0])->toBeGreaterThan(180);
});

it('blends where the moved person stood into what is around it, so no patch or old shadow shows', function () {
    Queue::fake();
    $blended = imagecreatetruecolor(432, 768);
    imagefill($blended, 0, 0, (int) imagecolorallocate($blended, 20, 200, 20));
    ob_start();
    imagepng($blended);
    $green = (string) ob_get_clean();
    Http::fake([
        'api.replicate.com/v1/models/*' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/blended.png']),
        'replicate.delivery/blended.png' => Http::response($green),
    ]);
    $keyframe = keyframeOnPlace($this->project);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $fill = Http::recorded(fn(Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'flux-fill'))->first()[0];
    $mask = new Imagick();
    $mask->readImageBlob((string) base64_decode(substr($fill['input']['mask'], strpos($fill['input']['mask'], ',') + 1)));
    $white = fn(int $x, int $y) => $mask->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_RED) > 0.5;

    // White where he stood, black where he stands now: only the vacated spot is drawn again.
    expect($white(300, 540))->toBeTrue()
        ->and($white(100, 540))->toBeFalse()
        ->and($white(20, 20))->toBeFalse()
        ->and(pixelAt($keyframe->fresh()->render()->getPath(), 300, 540))->toBe([20, 200, 20]);
});

it('keeps the move as it is when blending is switched off', function () {
    Queue::fake();
    Config::set('pipeline.keyframes.move.blend', false);
    Http::fake();
    $keyframe = keyframeOnPlace($this->project);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    Http::assertNotSent(fn(Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'flux-fill'));
});

it('gives the depth of the place when a person is clicked, so the editor sizes them while dragging', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->getFirstMedia(Shot::PLATE)->setCustomProperty(PlaceDepth::DEPTH, (new DepthGrid(2, 2, [8.0, 8.0, 4.0, 2.0]))->toArray())->save();

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('depth.width'))->toBe(2)
        ->and($response->json('depth.values'))->toEqualWithDelta([8.0, 8.0, 4.0, 2.0], 0.01);
});

it('hides the moved person behind what the place has in front of them there', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;
    // Far everywhere, except a counter on the left between 480 and 576 high, much nearer than the floor where the feet go.
    $values = array_fill(0, 8 * 16, 10.0);
    foreach ([10, 11] as $row) {
        foreach ([0, 1, 2] as $column) {
            $values[$row * 8 + $column] = 5.0;
        }
    }
    $shot->getFirstMedia(Shot::PLATE)->setCustomProperty(PlaceDepth::DEPTH, (new DepthGrid(8, 16, $values))->toArray())->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    // Behind the counter the place shows, up to a soft edge; above it and at the feet, the person.
    expect(pixelAt($moved, 100, 530))->toEqualWithDelta([90, 110, 160], 6)
        ->and(pixelAt($moved, 100, 450)[0])->toBeGreaterThan(180)
        ->and(pixelAt($moved, 100, 590)[0])->toBeGreaterThan(180);
});

it('fits the moved person to the new spot only when the director asks', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move.fit', [$this->project, $shot, $keyframe]), ['instruction' => 'He looks at the bin.'])
        ->assertSessionHasErrors('instruction');

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1]);
    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move.fit', [$this->project, $shot, $keyframe]), ['instruction' => 'He looks at the bin.'])
        ->assertSessionHasNoErrors();

    expect($keyframe->fresh()->rendering)->toBeTrue();
    Queue::assertPushed(PoseMovedPerson::class, fn(PoseMovedPerson $job) => $job->redraw && $job->instruction === 'He looks at the bin.' && abs($job->box['x'] + $job->box['width'] / 2 - 100 / 432) < 0.03);
});

it('tells the redraw which way the object they look at lies, on a tight crop', function () {
    $keyframe = keyframeOnPlace($this->project);
    $plate = $keyframe->shot->getFirstMedia(Shot::PLATE);
    $bin = Element::factory()->for($this->project)->create(['type' => ElementType::OBJECT, 'name' => 'Ash bin', 'detection_words' => ['bin']]);
    $keyframe->elements()->attach($bin);
    // The bin stands at the lower right of the place.
    $mask = imagecreatetruecolor(216, 384);
    imagefilledrectangle($mask, 180, 280, 210, 330, (int) imagecolorallocate($mask, 255, 255, 255));
    ob_start();
    imagepng($mask);
    $plate->setCustomProperty(PlaceObjects::PRESENT, [(string) $bin->id => true])
        ->setCustomProperty(PlaceObjects::MASKS, [$bin->id . ':' . hash('xxh3', (string) json_encode(['bin'])) => base64_encode((string) ob_get_clean())])
        ->save();
    $box = app(TopLayerCutout::class)->select($plate->getPath(), $keyframe->render()->getPath(), 300 / 432, 520 / 768)['box'];
    Image::fake(fn(ImagePrompt $prompt) => base64_encode(Storage::disk('local')->get($prompt->attachments->first()->path)));
    MovedPersonDescriber::fake([['description' => 'He looks at the bin.', 'storyline' => '', 'title' => '', 'warning' => '']]);

    (new PoseMovedPerson($keyframe, 'He looks at the ash bin.', $box))->handle(app(KeyframePainter::class), app(TopLayerCutout::class), app(BackgroundDrift::class));

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The Ash bin is toward the lower right of them')
        && $prompt->contains('Their feet stay exactly where they are.'));
});

it('only makes the plan follow a plain move, without redrawing', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->forceFill(['storyline' => ['keyframes' => [['title' => 'Wrong Zone', 'description' => $keyframe->description]]]])->save();
    Image::fake();
    MovedPersonDescriber::fake([['description' => 'He stands in the blue lane.', 'storyline' => '', 'title' => '', 'warning' => '']]);
    $render = $keyframe->render_id;

    (new PoseMovedPerson($keyframe, '', ['x' => 0.6, 'y' => 0.5, 'width' => 0.1, 'height' => 0.2], redraw: false))->handle(app(KeyframePainter::class), app(TopLayerCutout::class), app(BackgroundDrift::class));

    expect($keyframe->fresh()->description)->toBe('He stands in the blue lane.')
        ->and($keyframe->fresh()->render_id)->toBe($render);
    Image::assertNothingGenerated();
});

it('measures the depth of a place once and keeps it on the place', function () {
    Config::set('services.replicate.token', 'test-token');
    $keyframe = keyframeOnPlace($this->project);
    $plate = $keyframe->shot->getFirstMedia(Shot::PLATE);
    // Depth Anything answers with float32 rows: near at the bottom, far at the top.
    $rows = [];
    foreach (range(0, 7) as $row) {
        foreach (range(0, 3) as $column) {
            $rows[] = 8.0 - $row;
        }
    }
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response(['status' => 'succeeded', 'output' => ['data' => ['https://replicate.delivery/depth.json']]]),
        'replicate.delivery/depth.json' => Http::response(['depth' => ['shape' => [8, 4], 'dtype' => 'float32', 'data' => base64_encode(pack('g*', ...$rows))]]),
    ]);

    $grid = app(PlaceDepth::class)->of($plate);
    $again = app(PlaceDepth::class)->of($plate->fresh());

    expect($grid->at(0.5, 0.05))->toEqualWithDelta(8.0, 0.01)
        ->and($grid->at(0.5, 0.95))->toEqualWithDelta(1.0, 0.01)
        ->and($again->at(0.5, 0.95))->toEqualWithDelta(1.0, 0.01);
    Http::assertSentCount(2);
});

it('only offers moving on a keyframe drawn on a place', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->clearMediaCollection(Shot::PLATE);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $keyframe->shot, $keyframe]), ['x' => 0.7, 'y' => 0.7])
        ->assertJsonValidationErrors('x');
});

it('redraws the pose on a crop around the person, again when they wander off, and makes the plan true to the image before the review', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->forceFill(['storyline' => ['keyframes' => [['title' => 'Wrong Zone', 'description' => $keyframe->description, 'must_show' => 'He stands inside the red zone.']]]])->save();
    $box = app(TopLayerCutout::class)->select($keyframe->shot->getFirstMedia(Shot::PLATE)->getPath(), $keyframe->render()->getPath(), 300 / 432, 520 / 768)['box'];
    $calls = 0;
    // The first redraw loses the person; the second gives back the crop it was sent, so the person stays put.
    Image::fake(function (ImagePrompt $prompt) use (&$calls) {
        $calls++;

        return $calls === 1 ? base64_encode(placePng()) : base64_encode(Storage::disk('local')->get($prompt->attachments->first()->path));
    });
    $keyframe->shot->forceFill(['chosen_storyline' => ['title' => 'Under the load', 'storyline' => 'He steps into the red zone and points at the container.']])->save();
    MovedPersonDescriber::fake([['description' => 'He stands in the blue lane, side-on, and points at the container.', 'storyline' => 'He stops in the blue lane and points at the container.', 'title' => 'Safe Lane', 'warning' => 'He never enters the red zone, so the mistake the shot warns about is not shown.']]);
    $keyframe->forceFill(['rendering' => true])->save();

    (new PoseMovedPerson($keyframe, 'Keep the person exactly where they stand. He points at the container.', $box))->handle(app(KeyframePainter::class), app(TopLayerCutout::class), app(BackgroundDrift::class));

    $keyframe->refresh();
    $render = $keyframe->render();

    expect($calls)->toBe(2)
        ->and($keyframe->rendering)->toBeFalse()
        ->and($render->getCustomProperty(Keyframe::TWEAK_REQUEST))->toContain('He points at the container.')
        ->and($render->getCustomProperty(Keyframe::MOVE_WARNING))->toBe('He never enters the red zone, so the mistake the shot warns about is not shown.')
        ->and($keyframe->title)->toBe('Safe Lane')
        ->and($keyframe->shot->storylineKeyframes()[0]['title'])->toBe('Safe Lane')
        ->and($keyframe->shot->fresh()->chosenStoryline())->toBe(['title' => 'Under the load', 'storyline' => 'He stops in the blue lane and points at the container.'])
        ->and($keyframe->description)->toBe('He stands in the blue lane, side-on, and points at the container.')
        ->and($render->getCustomProperty(Keyframe::SENT)['images'])->toBe(['A crop around the person moved by hand, the image that is edited']);
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: Keep the person exactly where they stand.'));
    MovedPersonDescriber::assertPrompted(fn($prompt) => ! str_contains($prompt->prompt, 'Must show'));
});

it('still makes the plan true to the moved image when the pose cannot be redrawn', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->forceFill(['storyline' => ['keyframes' => [['title' => 'Wrong Zone', 'description' => $keyframe->description]]]])->save();
    MovedPersonDescriber::fake([['description' => 'He stands in the blue lane.', 'storyline' => '', 'title' => '', 'warning' => '']]);
    $keyframe->forceFill(['rendering' => true])->save();

    (new PoseMovedPerson($keyframe, 'Keep the person where they stand.', ['x' => 0.6, 'y' => 0.5, 'width' => 0.1, 'height' => 0.2]))->failed(new RuntimeException('Provider down'));

    expect($keyframe->fresh()->rendering)->toBeFalse()
        ->and($keyframe->fresh()->render_error)->toContain('it could not be redrawn there')
        ->and($keyframe->fresh()->description)->toBe('He stands in the blue lane.');
});

it('lets the director move people in keyframe 1 and pick its versions before the other keyframes are drawn', function () {
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;
    $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
    $first = $keyframe->render_id;
    $second = $keyframe->addMediaFromString(placePng([300, 600]))->usingFileName('keyframe-1-b.png')->toMediaCollection(Keyframe::RENDERS);
    $later = Keyframe::factory()->for($shot)->create(['position' => 2]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.render', [$this->project, $shot, $keyframe]), ['render' => $second->id])
        ->assertSessionHasNoErrors();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.render', [$this->project, $shot, $later]), ['render' => $first])
        ->assertSessionHasErrors('render');

    expect($keyframe->fresh()->render_id)->toBe($second->id)
        ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);
});

it('measures the depth of a place as soon as it is chosen, so moving is instant later', function () {
    Queue::fake();
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::FIRST_KEYFRAME_READY]);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $option = $shot->addMediaFromString(placePng())->usingFileName('place.png')->toMediaCollection(Shot::PLATE_OPTIONS);

    app(FirstKeyframeChoice::class)->choosePlace($shot->fresh(), $option);

    Queue::assertPushed(MeasurePlaceDepth::class, fn(MeasurePlaceDepth $job) => $job->place->collection_name === Shot::PLATE);
});

it('moves a person whole with the cut-out model\'s mask, also where they are nearly the colour of the floor, and keeps the moved mask', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;
    // Shoes nearly the colour of the floor: by what differs from the place they would stay behind.
    $render = imagecreatefromstring(placePng([300, 600]));
    imagefilledrectangle($render, 275, 585, 325, 600, (int) imagecolorallocate($render, 92, 112, 162));
    ob_start();
    imagepng($render);
    $keyframe->render()->delete();
    $version = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('keyframe-1.png')->toMediaCollection(Keyframe::RENDERS);
    $mask = imagecreatetruecolor(432, 768);
    imagefilledrectangle($mask, 275, 440, 325, 600, (int) imagecolorallocate($mask, 255, 255, 255));
    ob_start();
    imagepng($mask);
    $version->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render();
    $movedMask = imagecreatefromstring(base64_decode($moved->getCustomProperty(Keyframe::TOP_LAYER_MASK)));

    // The shoes came along, and the mask follows the person to the new spot.
    expect(pixelAt($moved->getPath(), 100, 595))->toEqualWithDelta([92, 112, 162], 8)
        ->and(pixelAt($moved->getPath(), 300, 595))->toEqualWithDelta([90, 110, 160], 4)
        ->and(imagecolorsforindex($movedMask, imagecolorat($movedMask, 100, 520))['red'])->toBeGreaterThan(200)
        ->and(imagecolorsforindex($movedMask, imagecolorat($movedMask, 300, 520))['red'])->toBeLessThan(40);
});

it('leaves everyone else where they are when one person is moved', function () {
    Queue::fake();
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
    $shot->addMediaFromString(placePng())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1]);
    // Two people: the one moved at (300, 600), another at (80, 600) who stays.
    $two = imagecreatefromstring(placePng([300, 600]));
    imagefilledrectangle($two, 55, 440, 105, 600, (int) imagecolorallocate($two, 40, 200, 40));
    ob_start();
    imagepng($two);
    $version = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('keyframe-1.png')->toMediaCollection(Keyframe::RENDERS);
    $mask = imagecreatetruecolor(432, 768);
    imagefilledrectangle($mask, 275, 440, 325, 600, (int) imagecolorallocate($mask, 255, 255, 255));
    imagefilledrectangle($mask, 55, 440, 105, 600, (int) imagecolorallocate($mask, 255, 255, 255));
    ob_start();
    imagepng($mask);
    $version->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -100 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    expect(pixelAt($moved, 80, 520))->toBe([40, 200, 40])
        ->and(pixelAt($moved, 200, 520)[0])->toBeGreaterThan(200)
        ->and(pixelAt($moved, 300, 520))->toEqualWithDelta([90, 110, 160], 4);
});

it('makes an outdated mask again the way the composite does, so a helicopter flying in can be moved like a person', function () {
    Config::set('services.replicate.token', 'test-token');
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    $shape = function (bool $alpha): string {
        $mask = imagecreatetruecolor(432, 768);
        imagesavealpha($mask, $alpha);
        imagefill($mask, 0, 0, (int) imagecolorallocatealpha($mask, 0, 0, 0, $alpha ? 127 : 0));
        imagefilledrectangle($mask, 275, 440, 325, 600, (int) imagecolorallocatealpha($mask, 255, 255, 255, 0));
        ob_start();
        imagepng($mask);

        return (string) ob_get_clean();
    };
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(Illuminate\Http\Client\Request $request) => Http::response(['status' => 'succeeded', 'output' => match (true) {
            ($request['version'] ?? null) === $people => 'https://replicate.delivery/front.png',
            ($request['input']['text_prompt'] ?? null) === 'grey helicopter' => 'https://replicate.delivery/thing.png',
            default => 'https://replicate.delivery/missing.png',
        }]),
        'replicate.delivery/front.png' => Http::response($shape(true)),
        'replicate.delivery/thing.png' => Http::response($shape(false)),
        'replicate.delivery/missing.png' => Http::response('', 404),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => false]);
    App\Ai\Agents\DetectionWordsWriter::fake(fn() => ['words' => ['grey helicopter']]);

    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;
    $shot->forceFill(['storyline' => ['keyframes' => [['title' => 'In', 'description' => 'The helicopter flies in.', 'handled' => ['Site helicopter']]]]])->save();
    $helicopter = Element::factory()->object()->for($this->project)->create(['name' => 'Site helicopter']);
    $helicopter->addMediaFromString(placePng())->usingFileName('helicopter.png')->toMediaCollection(Element::REFERENCE);
    $keyframe->elements()->attach($helicopter);
    // A mask from before the helicopter was handled: nothing on top.
    $keyframe->render()->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode((function () {
        ob_start();
        imagepng(imagecreatetruecolor(432, 768));

        return (string) ob_get_clean();
    })()))->save();

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('box.x'))->toEqualWithDelta(275 / 432, 0.03)
        ->and($keyframe->fresh()->render()->getCustomProperty(Keyframe::TOP_LAYER_KEY))->toBe(app(KeyframePainter::class)->movableKey($keyframe->fresh()->load('shot')));
});

it('keeps a moved composite a composite, so a later change of the place does not draw it again', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->render()->setCustomProperty(Keyframe::COMPOSITED, true)->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    expect($keyframe->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue();
});

it('moves a thing, such as a helicopter, without a shadow on the floor and without a pose to fit', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $mask = imagecreatetruecolor(432, 768);
    imagefilledrectangle($mask, 275, 440, 325, 600, (int) imagecolorallocate($mask, 255, 255, 255));
    ob_start();
    imagepng($mask);
    $shape = base64_encode((string) ob_get_clean());
    $keyframe->render()->setCustomProperty(Keyframe::TOP_LAYER_MASK, $shape)->setCustomProperty(Keyframe::THINGS_MASK, $shape)->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render();

    // Just under where it was put down, the floor is untouched: no shadow is added.
    expect(pixelAt($moved->getPath(), 100, 603))->toEqualWithDelta([90, 110, 160], 3)
        ->and($moved->getCustomProperty(Keyframe::MOVED_THING))->toBeTrue()
        ->and($moved->getCustomProperty(Keyframe::THINGS_MASK))->toBeString();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move.fit', [$this->project, $keyframe->shot, $keyframe]), ['instruction' => ''])
        ->assertSessionHasErrors('instruction');
});

/**
 * A shot whose place has a landed helicopter, the red block with its foot at (300, 600), and keyframe 1 is that place.
 */
function keyframeOnPlaceWithHelicopter(Project $project): Keyframe
{
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.composite.enabled', true);
    $mask = imagecreatetruecolor(432, 768);
    imagefilledrectangle($mask, 275, 440, 325, 600, (int) imagecolorallocate($mask, 255, 255, 255));
    ob_start();
    imagepng($mask);
    $rectangle = (string) ob_get_clean();
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(Illuminate\Http\Client\Request $request) => Http::response(['status' => 'succeeded', 'output' => ($request['version'] ?? null) === $people ? 'https://replicate.delivery/front.png' : 'https://replicate.delivery/thing.png']),
        'replicate.delivery/*' => Http::response($rectangle),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => true]);
    App\Ai\Agents\DetectionWordsWriter::fake(fn() => ['words' => ['grey helicopter']]);

    $shot = Shot::factory()->for($project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['keyframes' => [['title' => 'Landed', 'description' => 'The helicopter stands on the deck.', 'elements' => ['Site helicopter'], 'handled' => []]]]]);
    $plate = $shot->addMediaFromString(placePng([300, 600]))->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'The helicopter stands on the deck.']);
    $helicopter = Element::factory()->object()->for($project)->create(['name' => 'Site helicopter']);
    $helicopter->addMediaFromString(placePng())->usingFileName('helicopter.png')->toMediaCollection(Element::REFERENCE);
    $keyframe->elements()->attach($helicopter);
    app(KeyframePainter::class)->useChosenPlate($keyframe, $plate);

    return $keyframe->fresh();
}

it('picks up a thing of the place, such as a landed helicopter, and says it moves in this keyframe only', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('placeObject'))->toBe('Site helicopter')
        ->and($response->json('box.x'))->toEqualWithDelta(275 / 432, 0.03);
    Queue::assertPushed(App\Jobs\EmptyPlace::class);
});

it('makes the chosen place without its named things in the background, with a mask of each', function () {
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);
    // The edit model takes the helicopter out: the place without it.
    Image::fake(fn() => base64_encode(placePng()));
    $plate = $keyframe->shot->getFirstMedia(Shot::PLATE);

    (new App\Jobs\EmptyPlace($keyframe->shot, $plate))->handle(app(KeyframePainter::class));

    $emptied = app(KeyframePainter::class)->emptiedPlate($keyframe->shot->fresh(), $plate);

    expect($emptied)->not->toBeNull()
        ->and(pixelAt($emptied->getPath(), 300, 520))->toEqualWithDelta([90, 110, 160], 12)
        ->and(pixelAt($emptied->getPath(), 20, 20))->toEqualWithDelta([128, 128, 128], 4)
        ->and(array_keys($emptied->getCustomProperty(KeyframePainter::EMPTIED_MASKS)))->toEqual([$keyframe->elements->first()->id]);
});

it('asks to wait while the place is not emptied yet, and starts emptying it', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasErrors('x');

    Queue::assertPushed(App\Jobs\EmptyPlace::class);
});

it('moves a thing of the place in this keyframe only, on the place without its named things', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);
    Image::fake(fn() => base64_encode(placePng()));
    $shot = $keyframe->shot;
    $second = Keyframe::factory()->for($shot)->create(['position' => 2]);
    $secondRender = $second->addMediaFromString(placePng([300, 600]))->usingFileName('keyframe-2.png')->toMediaCollection(Keyframe::RENDERS);
    $second->forceFill(['render_id' => $secondRender->id])->save();
    app(KeyframePainter::class)->emptyPlate($shot->load('project'), $shot->getFirstMedia(Shot::PLATE));

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $render = $keyframe->fresh()->render();

    // Gone where it stood, at the new spot instead; the other keyframe and the place stay as they were.
    expect(pixelAt($render->getPath(), 300, 520))->toEqualWithDelta([90, 110, 160], 12)
        ->and(pixelAt($render->getPath(), 100, 520))->toEqualWithDelta([220, 40, 40], 12)
        ->and($render->getCustomProperty(Keyframe::ON_EMPTIED_PLACE))->toBeTrue()
        ->and($render->getCustomProperty(Keyframe::MOVED_THING))->toBeTrue()
        ->and($second->fresh()->render_id)->toBe($secondRender->id)
        ->and(pixelAt($shot->fresh()->getFirstMedia(Shot::PLATE)->getPath(), 300, 520))->toEqualWithDelta([220, 40, 40], 12)
        ->and($keyframe->fresh()->rendering)->toBeFalse();
    Queue::assertPushed(PoseMovedPerson::class);

    // Moved again: it is on top now, and the place without it shows where it was.
    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 100 / 432, 'y' => 520 / 768, 'dx' => 100 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $again = $keyframe->fresh()->render();

    expect(pixelAt($again->getPath(), 100, 520))->toEqualWithDelta([90, 110, 160], 12)
        ->and(pixelAt($again->getPath(), 200, 520))->toEqualWithDelta([220, 40, 40], 12);
});

it('keeps the keyframes waiting while the place is made ready for moving things, and starts making it ready', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $keyframe->shot]))
        ->assertInertia(fn(Inertia\Testing\AssertableInertia $page) => $page
            ->where('keyframes.0.placePreparing', true)
            ->where('keyframes.0.links.moveSelect', null)
            ->where('keyframes.0.links.move', null));

    Queue::assertPushed(App\Jobs\EmptyPlace::class);
});

it('lets the keyframes be moved once the place is ready, also when emptying it failed', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);
    $job = new App\Jobs\EmptyPlace($keyframe->shot, $keyframe->shot->getFirstMedia(Shot::PLATE));
    $job->failed(new RuntimeException('The image model is down.'));
    (new MeasurePlaceDepth($keyframe->shot->getFirstMedia(Shot::PLATE)))->failed(new RuntimeException('The depth model is down.'));

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $keyframe->shot]))
        ->assertInertia(fn(Inertia\Testing\AssertableInertia $page) => $page
            ->where('keyframes.0.placePreparing', false)
            ->whereType('keyframes.0.links.moveSelect', 'string'));

    Queue::assertNotPushed(App\Jobs\EmptyPlace::class);
    Queue::assertNotPushed(MeasurePlaceDepth::class);
});

it('never measures the depth of the place on a click, and waits for it while it is measured', function () {
    Queue::fake();
    $keyframe = keyframeOnPlaceWithHelicopter($this->project);
    $keyframe->shot->getFirstMedia(Shot::PLATE)->setCustomProperty(Shot::PLATE_EMPTIED_DONE, true)->save();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $keyframe->shot]))
        ->assertInertia(fn(Inertia\Testing\AssertableInertia $page) => $page->where('keyframes.0.placePreparing', true));
    Queue::assertPushed(MeasurePlaceDepth::class);

    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('depth'))->toBeNull();
    Http::assertNotSent(fn(Illuminate\Http\Client\Request $request) => ($request['version'] ?? null) === Config::get('pipeline.keyframes.composite.depth_model'));
});

it('takes the shadow a person leaves on the floor along when they are moved, and leaves the shadow of someone else', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    // Two people with long shadows to their left: the one at x 300, moved, and the one at x 100, who stays.
    $render = imagecreatefromstring(placePng([300, 600]));
    $red = (int) imagecolorallocate($render, 220, 40, 40);
    imagefilledrectangle($render, 75, 440, 125, 600, $red);
    $shadow = (int) imagecolorallocate($render, 54, 66, 96);
    imagefilledrectangle($render, 215, 585, 274, 600, $shadow);
    imagefilledrectangle($render, 30, 585, 74, 600, $shadow);
    ob_start();
    imagepng($render);
    $keyframe->render()->delete();
    $version = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('keyframe-1.png')->toMediaCollection(Keyframe::RENDERS);
    $mask = imagecreatetruecolor(432, 768);
    $white = (int) imagecolorallocate($mask, 255, 255, 255);
    imagefilledrectangle($mask, 275, 440, 325, 600, $white);
    imagefilledrectangle($mask, 75, 440, 125, 600, $white);
    ob_start();
    imagepng($mask);
    $version->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => 0, 'dy' => -150 / 768, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    expect(pixelAt($moved, 240, 592))->toEqualWithDelta([90, 110, 160], 6)
        ->and(pixelAt($moved, 50, 592))->toEqualWithDelta([54, 66, 96], 6);
});

it('takes the shadow a thing leaves on the floor along when it is moved, such as a truck', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    // Two people with long shadows to their left: the one at x 300, moved, and the one at x 100, who stays.
    $render = imagecreatefromstring(placePng([300, 600]));
    $red = (int) imagecolorallocate($render, 220, 40, 40);
    imagefilledrectangle($render, 75, 440, 125, 600, $red);
    $shadow = (int) imagecolorallocate($render, 54, 66, 96);
    imagefilledrectangle($render, 215, 585, 274, 600, $shadow);
    imagefilledrectangle($render, 30, 585, 74, 600, $shadow);
    ob_start();
    imagepng($render);
    $keyframe->render()->delete();
    $version = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('keyframe-1.png')->toMediaCollection(Keyframe::RENDERS);
    $mask = imagecreatetruecolor(432, 768);
    $white = (int) imagecolorallocate($mask, 255, 255, 255);
    imagefilledrectangle($mask, 275, 440, 325, 600, $white);
    imagefilledrectangle($mask, 75, 440, 125, 600, $white);
    ob_start();
    imagepng($mask);
    $both = (string) ob_get_clean();
    $thing = imagecreatetruecolor(432, 768);
    imagefilledrectangle($thing, 275, 440, 325, 600, (int) imagecolorallocate($thing, 255, 255, 255));
    ob_start();
    imagepng($thing);
    $version->setCustomProperty(Keyframe::TOP_LAYER_MASK, base64_encode($both))->setCustomProperty(Keyframe::THINGS_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $keyframe->shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => 0, 'dy' => -150 / 768, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    expect(pixelAt($moved, 240, 592))->toEqualWithDelta([90, 110, 160], 6)
        ->and(pixelAt($moved, 50, 592))->toEqualWithDelta([54, 66, 96], 6);
});

it('keeps the part of a person moved out of the frame, so they come back whole', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    // Half out of the frame on the right: x 375 to 425 of 432 stays in, the rest is cut off.
    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => 125 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    expect($keyframe->fresh()->getMedia(Keyframe::LAYERS))->toHaveCount(1);

    // Back in: dragged by the part still in the frame.
    $response = actingAs($this->director, 'director')
        ->postJson(route('public.shots.keyframes.move.select', [$this->project, $shot, $keyframe]), ['x' => 415 / 432, 'y' => 520 / 768])
        ->assertOk();

    expect($response->json('box.x') + $response->json('box.width'))->toBeGreaterThan(1.0);

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 415 / 432, 'y' => 520 / 768, 'dx' => -225 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    // Whole again, also its right edge that was outside the frame; one layer, where it lies now.
    expect(pixelAt($moved, 180, 520))->toEqualWithDelta([220, 40, 40], 12)
        ->and(pixelAt($moved, 220, 520))->toEqualWithDelta([220, 40, 40], 12)
        ->and($keyframe->fresh()->getMedia(Keyframe::LAYERS)->filter(fn($layer) => $layer->getCustomProperty('render') === $keyframe->fresh()->render_id))->toHaveCount(1);
});

it('shows each keyframe its part of the storyline', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->forceFill(['storyline' => ['keyframes' => [['title' => 'Look', 'beat' => 'He looks up at the container.', 'description' => 'He looks up at the container and points at it.']]]])->save();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $keyframe->shot]))
        ->assertInertia(fn(Inertia\Testing\AssertableInertia $page) => $page->where('keyframes.0.beat', 'He looks up at the container.'));
});
