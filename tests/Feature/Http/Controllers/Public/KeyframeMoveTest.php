<?php

declare(strict_types=1);

use App\Ai\Agents\MovedPersonDescriber;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\PoseMovedPerson;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\BackgroundDrift;
use App\Support\Images\PersonCutout;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

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

it('moves the person, shows the place where they stood, and has the pose redrawn for the new spot', function () {
    Queue::fake();
    $keyframe = keyframeOnPlace($this->project);
    $shot = $keyframe->shot;

    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.move', [$this->project, $shot, $keyframe]), ['x' => 300 / 432, 'y' => 520 / 768, 'dx' => -200 / 432, 'dy' => 0, 'scale' => 1, 'instruction' => ''])
        ->assertSessionHasNoErrors();

    $moved = $keyframe->fresh()->render();

    expect($moved->getCustomProperty(Keyframe::MOVED_BY_HAND))->toBeTrue()
        ->and(pixelAt($moved->getPath(), 300, 540))->toBe([90, 110, 160])
        ->and(pixelAt($moved->getPath(), 100, 540)[0])->toBeGreaterThan(200)
        ->and($keyframe->fresh()->rendering)->toBeTrue();
    Queue::assertPushed(PoseMovedPerson::class, fn(PoseMovedPerson $job) => str_contains($job->instruction, 'Keep the person exactly where they stand')
        && str_contains($job->instruction, 'He looks up at the container and points at it.')
        && abs($job->box['x'] + $job->box['width'] / 2 - 100 / 432) < 0.03);
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
        ->and($render->getCustomProperty(Keyframe::MOVED_AWAY))->toBeNull()
        ->and($render->getCustomProperty(Keyframe::TWEAK_REQUEST))->toContain('He points at the container.')
        ->and($render->getCustomProperty(Keyframe::MOVE_WARNING))->toBe('He never enters the red zone, so the mistake the shot warns about is not shown.')
        ->and($keyframe->title)->toBe('Safe Lane')
        ->and($keyframe->shot->storylineKeyframes()[0]['title'])->toBe('Safe Lane')
        ->and($keyframe->shot->fresh()->chosenStoryline())->toBe(['title' => 'Under the load', 'storyline' => 'He stops in the blue lane and points at the container.'])
        ->and($keyframe->description)->toBe('He stands in the blue lane, side-on, and points at the container.')
        ->and($keyframe->shot->storylineKeyframes()[0])->not->toHaveKey('must_show')
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
