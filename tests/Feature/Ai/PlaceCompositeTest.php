<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineWriter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\PlaceComposite;
use App\Support\Shots\ShotPlan;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A PNG drawn with GD, 144 by 256, filled by the callback.
 */
function compositePng(Closure $draw, bool $alpha = false): string
{
    $image = imagecreatetruecolor(144, 256);

    if ($alpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    $draw($image);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A place: grey with vertical stripes, moved `$shift` pixels, as a redraw moves it.
 */
function stripedPlace(int $shift = 0): string
{
    return compositePng(function (GdImage $image) use ($shift) {
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 120, 120, 120));

        for ($x = 0; $x < 144; $x++) {
            if (intdiv($x + 144 - $shift, 12) % 2 === 0) {
                imageline($image, $x, 0, $x, 255, (int) imagecolorallocate($image, 200, 200, 200));
            }
        }
    });
}

/**
 * A white rectangle on black: a mask.
 */
function rectangleMask(int $left, int $top, int $right, int $bottom): string
{
    return compositePng(fn(GdImage $image) => imagefilledrectangle($image, $left, $top, $right, $bottom, (int) imagecolorallocate($image, 255, 255, 255)));
}

/**
 * The colour at a point, as [red, green, blue] from 0 to 255.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function colourAt(string $png, int $x, int $y): array
{
    $image = new Imagick();
    $image->readImageBlob($png);
    $colour = $image->getImagePixelColor($x, $y)->getColor();

    return [$colour['r'], $colour['g'], $colour['b']];
}

/**
 * The cut-outs on Replicate: the people model answers with a transparent person, the object model with a mask.
 */
function fakeReplicate(): void
{
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');

    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response([
            'status' => 'succeeded',
            'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/thing.png',
        ]),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/thing.png' => Http::response(rectangleMask(20, 40, 60, 200)),
    ]);
}

describe('compositing', function () {
    it('keeps the people, their shadow and the named things from the drawn image, and the place everywhere else', function () {
        // The drawn image: the place moved by a redraw, a red person, their dark shadow beside the feet, a blue blob far away and a changed green thing.
        $drawn = compositePng(function (GdImage $image) {
            imagecopy($image, imagecreatefromstring(stripedPlace(5)), 0, 0, 0, 0, 144, 256);
            imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocate($image, 220, 30, 30));
            imagefilledrectangle($image, 82, 170, 96, 180, (int) imagecolorallocate($image, 20, 20, 20));
            imagefilledrectangle($image, 2, 2, 14, 14, (int) imagecolorallocate($image, 30, 30, 220));
            imagefilledrectangle($image, 110, 220, 130, 240, (int) imagecolorallocate($image, 30, 200, 30));
        });

        $composite = (new PlaceComposite())->keyframe(stripedPlace(), $drawn, rectangleMask(60, 100, 80, 180), [rectangleMask(110, 220, 130, 240)]);

        expect(colourAt($composite, 70, 140))->toBe([220, 30, 30])
            ->and(colourAt($composite, 89, 175))->toBe([20, 20, 20])
            ->and(colourAt($composite, 120, 230))->toBe([30, 200, 30])
            // Far from the people: the place itself, not the redrawn blob or the moved stripes.
            ->and(colourAt($composite, 8, 8))->toBe(colourAt(stripedPlace(), 8, 8))
            ->and(colourAt($composite, 30, 60))->toBe(colourAt(stripedPlace(), 30, 60));
    });

    it('takes only the changed thing from an edit of the place', function () {
        $edited = compositePng(function (GdImage $image) {
            imagecopy($image, imagecreatefromstring(stripedPlace(5)), 0, 0, 0, 0, 144, 256);
            imagefilledrectangle($image, 20, 40, 60, 200, (int) imagecolorallocate($image, 40, 60, 120));
        });

        $state = (new PlaceComposite())->state(stripedPlace(), $edited, [rectangleMask(20, 40, 60, 200), rectangleMask(25, 45, 55, 190)]);

        expect(colourAt($state, 40, 120))->toBe([40, 60, 120])
            ->and(colourAt($state, 100, 120))->toBe(colourAt(stripedPlace(), 100, 120));
    });
});

describe('painter', function () {
    beforeEach(function () {
        Config::set('pipeline.keyframes.composite.enabled', true);
        Config::set('services.replicate.token', 'test-token');
        // The edit model draws through chat in tests, so the image fake answers it.
        Config::set('pipeline.models.keyframe_edit', 'google/gemini-3.1-flash-image-preview');
    });

    it('needs a Replicate token to composite', function () {
        Config::set('services.replicate.token', null);

        expect(app(KeyframePainter::class)->composites())->toBeFalse();
    });

    it('makes the place with the door closed and draws the later keyframes on it, keeping only the people and named things', function () {
        fakeReplicate();
        Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'chosen_storyline' => ['title' => 'Door', 'storyline' => 'He closes the door and calls.'],
            'storyline' => ['keyframes' => [
                ['title' => 'Door open', 'description' => 'He stands by the open door.', 'place_change' => '', 'place_part' => ''],
                ['title' => 'Door closed', 'description' => 'He has closed the door.', 'place_change' => 'The door on the left is closed.', 'place_part' => 'door'],
                ['title' => 'Calling', 'description' => 'He lifts the phone.', 'place_change' => '', 'place_part' => ''],
            ]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        $phone = Element::factory()->object()->for($this->project)->create(['name' => 'Wall phone']);

        foreach ([1, 2, 3] as $position) {
            $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position, 'title' => "Keyframe {$position}", 'description' => $shot->storylineKeyframes()[$position - 1]['description']]);

            if ($position === 3) {
                $keyframe->elements()->attach($phone);
            }
        }

        (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

        $state = $shot->media()->where('collection_name', Shot::PLACE_STATES)->sole();
        $keyframes = $shot->keyframes()->with('media')->get();
        $second = $keyframes->firstWhere('position', 2)->render();
        $third = $keyframes->firstWhere('position', 3)->render();
        $stateImage = Storage::disk($state->disk)->get($state->getPathRelativeToRoot());
        $secondImage = Storage::disk($second->disk)->get($second->getPathRelativeToRoot());

        expect($state->getCustomProperty('position'))->toBe(2)
            ->and($state->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
            // Only the door area came from the yellow edit.
            ->and(colourAt($stateImage, 40, 120))->toBe([250, 200, 0])
            ->and(colourAt($stateImage, 120, 20))->toBe(colourAt(stripedPlace(), 120, 20))
            ->and($second->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
            ->and($third->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
            // The person comes from the drawn keyframe, the rest is the closed-door place.
            ->and(colourAt($secondImage, 70, 140))->toBe([250, 200, 0])
            ->and(colourAt($secondImage, 120, 20))->toBe(colourAt($stateImage, 120, 20))
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->renders()->count() <= 1))->toBeTrue();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this, which is how it looks from now on in the shot: The door on the left is closed.'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('He lifts the phone.') && $prompt->attachments->first()->path === $state->getPathRelativeToRoot());
        Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'door');
        Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'Wall phone');
    });

    it('keeps the drawn keyframe when the cut-out fails', function () {
        Http::fake(['api.replicate.com/*' => Http::response(['detail' => 'Invalid version'], 422)]);
        Image::fake(fn() => base64_encode(stripedPlace(3)));
        Config::set('pipeline.keyframes.drift.enabled', false);

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline' => ['keyframes' => [['title' => 'One', 'description' => 'He waits.'], ['title' => 'Two', 'description' => 'He waves.']]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        Keyframe::factory()->for($shot)->create(['position' => 1]);
        $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He waves.']);

        (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

        $render = $second->fresh()->render();

        expect($render)->not->toBeNull()
            ->and($render->getCustomProperty(Keyframe::COMPOSITED))->toBeNull();
    });
});

describe('planner', function () {
    it('asks for the place change and keeps it in the plan', function () {
        $shot = Shot::factory()->for($this->project)->create();

        expect((string) (new StorylineWriter($shot->load('project')))->instructions())->toContain('- Place change: when something fixed in the place changes state');

        ShotPlan::apply($shot, ['storyline' => 'He closes the door.', 'keyframes' => [
            ['title' => 'Open', 'description' => 'The door is open.', 'spatial' => '', 'place_change' => '', 'place_part' => '', 'elements' => []],
            ['title' => 'Closed', 'description' => 'He closed the door.', 'spatial' => '', 'place_change' => 'The door is closed.', 'place_part' => 'door', 'elements' => []],
        ]]);

        expect($shot->fresh()->storylineKeyframes()[1])->toMatchArray(['place_change' => 'The door is closed.', 'place_part' => 'door'])
            ->and($shot->fresh()->storylineKeyframes()[0])->not->toHaveKey('place_change');
    });
});
