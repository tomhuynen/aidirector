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
use App\Support\Images\PersonCutout;
use App\Support\Images\PlaceDepth;
use App\Support\Images\PlaceObjects;
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
    $box = app(PersonCutout::class)->select($plate->getPath(), $keyframe->render()->getPath(), 300 / 432, 520 / 768)['box'];
    Image::fake(fn(ImagePrompt $prompt) => base64_encode(Storage::disk('local')->get($prompt->attachments->first()->path)));
    MovedPersonDescriber::fake([['description' => 'He looks at the bin.', 'storyline' => '', 'title' => '', 'warning' => '']]);

    (new PoseMovedPerson($keyframe, 'He looks at the ash bin.', $box))->handle(app(KeyframePainter::class), app(PersonCutout::class), app(BackgroundDrift::class));

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The Ash bin is toward the lower right of them')
        && $prompt->contains('Their feet stay exactly where they are.'));
});

it('only makes the plan follow a plain move, without redrawing', function () {
    $keyframe = keyframeOnPlace($this->project);
    $keyframe->shot->forceFill(['storyline' => ['keyframes' => [['title' => 'Wrong Zone', 'description' => $keyframe->description]]]])->save();
    Image::fake();
    MovedPersonDescriber::fake([['description' => 'He stands in the blue lane.', 'storyline' => '', 'title' => '', 'warning' => '']]);
    $render = $keyframe->render_id;

    (new PoseMovedPerson($keyframe, '', ['x' => 0.6, 'y' => 0.5, 'width' => 0.1, 'height' => 0.2], redraw: false))->handle(app(KeyframePainter::class), app(PersonCutout::class), app(BackgroundDrift::class));

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
    $box = app(PersonCutout::class)->select($keyframe->shot->getFirstMedia(Shot::PLATE)->getPath(), $keyframe->render()->getPath(), 300 / 432, 520 / 768)['box'];
    $calls = 0;
    // The first redraw loses the person; the second gives back the crop it was sent, so the person stays put.
    Image::fake(function (ImagePrompt $prompt) use (&$calls) {
        $calls++;

        return $calls === 1 ? base64_encode(placePng()) : base64_encode(Storage::disk('local')->get($prompt->attachments->first()->path));
    });
    $keyframe->shot->forceFill(['chosen_storyline' => ['title' => 'Under the load', 'storyline' => 'He steps into the red zone and points at the container.']])->save();
    MovedPersonDescriber::fake([['description' => 'He stands in the blue lane, side-on, and points at the container.', 'storyline' => 'He stops in the blue lane and points at the container.', 'title' => 'Safe Lane', 'warning' => 'He never enters the red zone, so the mistake the shot warns about is not shown.']]);
    $keyframe->forceFill(['rendering' => true])->save();

    (new PoseMovedPerson($keyframe, 'Keep the person exactly where they stand. He points at the container.', $box))->handle(app(KeyframePainter::class), app(PersonCutout::class), app(BackgroundDrift::class));

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
        ->and($keyframe->fresh()->render_error)->toContain('the pose could not be redrawn')
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
    $version->setCustomProperty(Keyframe::PEOPLE_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render();
    $movedMask = imagecreatefromstring(base64_decode($moved->getCustomProperty(Keyframe::PEOPLE_MASK)));

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
    $version->setCustomProperty(Keyframe::PEOPLE_MASK, base64_encode((string) ob_get_clean()))->save();
    $keyframe->forceFill(['render_id' => $version->id])->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -100 / 432, 'dy' => 0, 'scale' => 1])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render()->getPath();

    expect(pixelAt($moved, 80, 520))->toBe([40, 200, 40])
        ->and(pixelAt($moved, 200, 520)[0])->toBeGreaterThan(200)
        ->and(pixelAt($moved, 300, 520))->toEqualWithDelta([90, 110, 160], 4);
});
