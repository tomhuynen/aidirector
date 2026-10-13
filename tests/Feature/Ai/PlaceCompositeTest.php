<?php

declare(strict_types=1);

use App\Ai\Agents\DetectionWordsWriter;
use App\Ai\Agents\StorylineWriter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\ChangePlace;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\Cutout;
use App\Support\Images\PlaceComposite;
use App\Support\Images\PlaceObjects;
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
            'output' => match (true) {
                $request['version'] === $people => 'https://replicate.delivery/people.png',
                ($request['input']['text_prompt'] ?? null) === Cutout::PERSON => 'https://replicate.delivery/person.png',
                default => 'https://replicate.delivery/thing.png',
            },
        ]),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/person.png' => Http::response(rectangleMask(60, 100, 80, 180)),
        'replicate.delivery/thing.png' => Http::response(rectangleMask(20, 40, 60, 200)),
    ]);
}

describe('compositing', function () {
    it('keeps the people, their shadow and the named things from the drawn image, and the place everywhere else', function () {
        // The drawn image: the place moved by a redraw, a red person, their dark shadow below the feet,
        // a redrawn wall edge right beside them, a blue blob far away and a changed green thing.
        $drawn = compositePng(function (GdImage $image) {
            imagecopy($image, imagecreatefromstring(stripedPlace(5)), 0, 0, 0, 0, 144, 256);
            imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocate($image, 220, 30, 30));
            imagefilledrectangle($image, 56, 182, 92, 190, (int) imagecolorallocate($image, 20, 20, 20));
            imagefilledrectangle($image, 88, 100, 94, 160, (int) imagecolorallocate($image, 20, 20, 20));
            imagefilledrectangle($image, 2, 2, 14, 14, (int) imagecolorallocate($image, 30, 30, 220));
            imagefilledrectangle($image, 110, 220, 130, 240, (int) imagecolorallocate($image, 30, 200, 30));
        });

        $composite = (new PlaceComposite())->keyframe(stripedPlace(), $drawn, rectangleMask(60, 100, 80, 180), [rectangleMask(110, 220, 130, 240)]);

        expect(colourAt($composite, 70, 140))->toBe([220, 30, 30])
            ->and(colourAt($composite, 70, 186))->toBe([20, 20, 20])
            ->and(colourAt($composite, 120, 230))->toBe([30, 200, 30])
            // Beside the person and far from them: the place itself, not the redrawn wall edge, the blob or the moved stripes.
            ->and(colourAt($composite, 92, 120))->toBe(colourAt(stripedPlace(), 92, 120))
            ->and(colourAt($composite, 8, 8))->toBe(colourAt(stripedPlace(), 8, 8))
            ->and(colourAt($composite, 30, 60))->toBe(colourAt(stripedPlace(), 30, 60));
    });

    it('adds no shadow of its own under feet the drawing gave none', function () {
        $flat = compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 150, 150, 150)));
        // A person from 100 to 180 high, with an arm hanging down beside the body to 140.
        $person = compositePng(function (GdImage $image) {
            $white = (int) imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 60, 100, 80, 180, $white);
            imagefilledrectangle($image, 81, 110, 86, 140, $white);
        });
        $drawn = compositePng(function (GdImage $image) {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 150, 150, 150));
            imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocate($image, 220, 30, 30));
            imagefilledrectangle($image, 81, 110, 86, 140, (int) imagecolorallocate($image, 220, 30, 30));
        });

        $composite = (new PlaceComposite())->keyframe($flat, $drawn, $person);

        expect(colourAt($composite, 70, 184))->toBe([150, 150, 150])
            ->and(colourAt($composite, 83, 143))->toBe([150, 150, 150])
            ->and(colourAt($composite, 70, 200))->toBe([150, 150, 150]);
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

it('takes only the thing that changed when the words also find another one further back', function () {
    // The door in front is closed now; the edit also redrew the small door further back.
    $edited = compositePng(function (GdImage $image) {
        imagecopy($image, imagecreatefromstring(stripedPlace()), 0, 0, 0, 0, 144, 256);
        imagefilledrectangle($image, 10, 40, 60, 220, (int) imagecolorallocate($image, 40, 60, 120));
        imagefilledrectangle($image, 100, 80, 120, 130, (int) imagecolorallocate($image, 30, 30, 30));
    });
    $doors = compositePng(function (GdImage $image) {
        imagefilledrectangle($image, 10, 40, 60, 220, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 100, 80, 120, 130, (int) imagecolorallocate($image, 255, 255, 255));
    });

    $state = (new PlaceComposite())->state(stripedPlace(), $edited, [$doors]);

    expect(colourAt($state, 35, 130))->toBe([40, 60, 120])
        ->and(colourAt($state, 110, 105))->toBe(colourAt(stripedPlace(), 110, 105));
});

describe('people', function () {
    beforeEach(fn() => Config::set('services.replicate.token', 'test-token'));

    it('keeps someone further back the cut-out takes for background, found by name', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/person.png']),
            // The cut-out keeps only the person in front.
            'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
            // Found by name: the one in front, and also the one half hidden behind the counter.
            'replicate.delivery/person.png' => Http::response(compositePng(function (GdImage $image) {
                imagefilledrectangle($image, 62, 100, 78, 175, (int) imagecolorallocate($image, 255, 255, 255));
                imagefilledrectangle($image, 100, 60, 120, 110, (int) imagecolorallocate($image, 255, 255, 255));
            })),
        ]);

        $mask = app(Cutout::class)->people(stripedPlace());

        expect(colourAt($mask, 70, 140))->toBe([255, 255, 255])
            ->and(colourAt($mask, 110, 80))->toBe([255, 255, 255])
            ->and(colourAt($mask, 20, 20))->toBe([0, 0, 0]);
        Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === Cutout::PERSON);
    });

    it('leaves out a large object the cut-out takes for the front, such as a bin, and keeps the whole person', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/person.png']),
            // The cut-out keeps the person with the bag she carries, and the bin in the foreground.
            'replicate.delivery/people.png' => Http::response(compositePng(function (GdImage $image) {
                $white = (int) imagecolorallocatealpha($image, 255, 255, 255, 0);
                imagefilledrectangle($image, 30, 100, 50, 180, $white);
                imagefilledrectangle($image, 50, 150, 60, 165, $white);
                imagefilledrectangle($image, 90, 170, 130, 230, $white);
            }, alpha: true)),
            // Found by name: only the person, without the bag.
            'replicate.delivery/person.png' => Http::response(rectangleMask(30, 100, 50, 180)),
        ]);

        $mask = app(Cutout::class)->people(stripedPlace());

        expect(colourAt($mask, 40, 140))->toBe([255, 255, 255])
            ->and(colourAt($mask, 56, 158))->toBe([255, 255, 255])
            ->and(colourAt($mask, 110, 200))->toBe([0, 0, 0]);
    });

    it('keeps only the person and what is right around them of a vehicle they sit in', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/person.png']),
            // The cut-out keeps the helicopter with the visitor in its door as one shape.
            'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 10, 80, 134, 200, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
            'replicate.delivery/person.png' => Http::response(rectangleMask(60, 100, 75, 140)),
        ]);

        $mask = app(Cutout::class)->people(stripedPlace());

        expect(colourAt($mask, 67, 120))->toBe([255, 255, 255])
            ->and(colourAt($mask, 20, 190))->toBe([0, 0, 0])
            ->and(colourAt($mask, 120, 90))->toBe([0, 0, 0]);
    });

    it('keeps nobody when nobody is found by name, whatever the cut-out keeps in front', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/person.png']),
            'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
            'replicate.delivery/person.png' => Http::response(compositePng(fn(GdImage $image) => null)),
        ]);

        expect(colourAt(app(Cutout::class)->people(stripedPlace()), 70, 140))->toBe([0, 0, 0]);
    });

    it('gives a thing found by name the edge of the cut-out, and leaves out what else stands in front', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/front.png']),
            // The cut-out: the helicopter and, apart from it, a bin.
            'replicate.delivery/front.png' => Http::response(compositePng(function (GdImage $image) {
                $white = (int) imagecolorallocatealpha($image, 255, 255, 255, 0);
                imagefilledrectangle($image, 20, 20, 90, 70, $white);
                imagefilledrectangle($image, 100, 180, 130, 230, $white);
            }, alpha: true)),
        ]);

        // The finder's mask misses the tail and the rotor tips of the helicopter.
        $mask = app(Cutout::class)->refined(stripedPlace(), rectangleMask(30, 25, 80, 65));

        expect(colourAt($mask, 25, 25))->toBe([255, 255, 255])
            ->and(colourAt($mask, 55, 45))->toBe([255, 255, 255])
            ->and(colourAt($mask, 115, 200))->toBe([0, 0, 0]);
        Http::assertSent(fn(HttpRequest $request) => ($request['version'] ?? null) === $people);
    });

    it('does not take what the cut-out keeps in front for people when finding them by name fails', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => $request['version'] === $people
                ? Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/people.png'])
                : Http::response(['detail' => 'Invalid version'], 422),
            'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        ]);

        expect(fn() => app(Cutout::class)->people(stripedPlace()))->toThrow(Illuminate\Http\Client\RequestException::class);
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
        DetectionWordsWriter::fake(fn() => ['words' => ['red wall phone', 'telephone']]);
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
                $keyframe->elements()->attach([$phone->id, Element::factory()->for($this->project)->create(['name' => "Caller {$keyframe->position}", 'type' => 'person'])->id]);
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
        // The phone is looked for once, in the place, by common words; it is touched, so it comes from the drawing.
        // Each a prediction and its download: the door in the place before and after, the people of keyframes 2 and 3 found by name,
        // their front cut out once since both are drawn alike here, the phone once.
        Http::assertSentCount(2 * (2 + 2 + 1 + 1));
        Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'red wall phone');
        Http::assertNotSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'Wall phone');
        expect($phone->fresh()->detection_words)->toBe(['red wall phone', 'telephone'])
            ->and($state->fresh()->getCustomProperty(PlaceObjects::MASKS))->toHaveCount(1);
    });

    it('changes the place from a keyframe on and puts the people of those keyframes onto it without drawing them again', function () {
        fakeReplicate();
        // The edit of the place: everything yellow, of which only the changed part is taken.
        Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_READY,
            'storyline' => ['keyframes' => [['title' => 'One', 'description' => 'He waits.'], ['title' => 'Two', 'description' => 'He waves.']]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        $renders = [];

        foreach ([1, 2] as $position) {
            $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position]);
            // A composite: a red person on the place.
            $render = $keyframe->addMediaFromString(compositePng(function (GdImage $image) {
                imagecopy($image, imagecreatefromstring(stripedPlace()), 0, 0, 0, 0, 144, 256);
                imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocate($image, 220, 30, 30));
            }))->usingFileName('k.png')->withCustomProperties([Keyframe::COMPOSITED => true])->toMediaCollection(Keyframe::RENDERS);
            $keyframe->forceFill(['render_id' => $render->id])->save();
            $renders[$position] = $render->id;
        }

        $shot->addPlaceEdit(2, 'The door has no handle.', 'door handle');
        (new ChangePlace($shot, 2))->handle(app(KeyframePainter::class));

        $keyframes = $shot->keyframes()->with('media')->get();
        $second = $keyframes->firstWhere('position', 2)->render();
        $image = Storage::disk($second->disk)->get($second->getPathRelativeToRoot());

        // Keyframe 1 is untouched; keyframe 2 keeps its person and gets the changed part of the place.
        expect($keyframes->firstWhere('position', 1)->render()->id)->toBe($renders[1])
            ->and($second->id)->not->toBe($renders[2])
            ->and($second->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
            ->and(colourAt($image, 70, 140))->toBe([220, 30, 30])
            ->and(colourAt($image, 40, 120))->toBe([250, 200, 0])
            ->and(colourAt($image, 120, 20))->toBe(colourAt(stripedPlace(), 120, 20))
            ->and($keyframes->every(fn(Keyframe $keyframe) => ! $keyframe->rendering))->toBeTrue();
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this, which is how it looks from now on in the shot: The door has no handle.'));
    });

    it('draws a change to the place once more when it does not show, such as a barrier arm still down', function () {
        fakeReplicate();
        $attempt = 0;
        App\Ai\Agents\PlaceChangeChecker::fake(function () use (&$attempt) {
            return ['shown' => ++$attempt > 1];
        });
        Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

        $shot = Shot::factory()->for($this->project)->create([
            'storyline' => ['keyframes' => [
                ['title' => 'Closed', 'description' => 'The barrier is down.', 'place_change' => '', 'place_part' => ''],
                ['title' => 'Open', 'description' => 'The barrier is up.', 'place_change' => 'The barrier arm is raised.', 'place_part' => 'barrier arm'],
            ]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);

        app(KeyframePainter::class)->ensurePlaceStates($shot->load('project'), collect());

        expect($shot->media()->where('collection_name', Shot::PLACE_STATES)->count())->toBe(1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The barrier arm is raised.')
            && $prompt->contains('The barrier arm stays in the place, exactly once'));
        App\Ai\Agents\PlaceChangeChecker::assertPromptedTimes(2);
        // A part gone, or a second copy of it still across the road, is no change.
        App\Ai\Agents\PlaceChangeChecker::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'The part that changes: the barrier arm.')
            && str_contains((string) $prompt->agent->instructions(), 'exactly once'));
    });

    it('tries a change to the place up to three times, since the edit model may remove or double a part', function () {
        fakeReplicate();
        App\Ai\Agents\PlaceChangeChecker::fake(fn() => ['shown' => false]);
        Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

        $shot = Shot::factory()->for($this->project)->create([
            'storyline' => ['keyframes' => [
                ['title' => 'Closed', 'description' => 'The barrier is down.', 'place_change' => '', 'place_part' => ''],
                ['title' => 'Open', 'description' => 'The barrier is up.', 'place_change' => 'The barrier arm is raised.', 'place_part' => 'barrier arm'],
            ]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);

        app(KeyframePainter::class)->ensurePlaceStates($shot->load('project'), collect());

        expect($shot->media()->where('collection_name', Shot::PLACE_STATES)->count())->toBe(1);
        App\Ai\Agents\PlaceChangeChecker::assertPromptedTimes(3);
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

    it('shows that the people are being placed while they are cut out, and clears it afterwards', function () {
        Config::set('pipeline.keyframes.drift.enabled', false);
        Config::set('pipeline.keyframe_check', false);
        Image::fake(fn() => base64_encode(stripedPlace(3)));

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline' => ['keyframes' => [['title' => 'One', 'description' => 'He waits.'], ['title' => 'Two', 'description' => 'He waves.']]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        Keyframe::factory()->for($shot)->create(['position' => 1]);
        $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He waves.']);
        $stages = [];
        Http::fake(function () use ($second, &$stages) {
            $stages[] = Keyframe::query()->whereKey($second->id)->value('render_stage');

            return Http::response(['detail' => 'Invalid version'], 422);
        });

        (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

        expect($stages)->not->toBeEmpty()
            ->and(array_unique($stages))->toBe([Keyframe::STAGE_COMPOSITING])
            ->and($second->fresh()->render_stage)->toBeNull()
            ->and($second->fresh()->rendering)->toBeFalse();
    });
});

describe('objects', function () {
    beforeEach(function () {
        Config::set('pipeline.keyframes.composite.enabled', true);
        Config::set('services.replicate.token', 'test-token');
    });

    it('takes an object from the drawing only when the people touch it', function () {
        $composite = new PlaceComposite();
        $person = rectangleMask(60, 100, 80, 180);

        expect($composite->touches(rectangleMask(82, 120, 100, 140), $person))->toBeTrue()
            ->and($composite->touches(rectangleMask(110, 20, 130, 40), $person))->toBeFalse();
    });

    it('keeps the keyframe as drawn when one of its objects cannot be found in the place', function () {
        $people = (string) Config::get('pipeline.keyframes.composite.people_model');
        Http::fake([
            'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => $request['version'] === $people
                ? Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/people.png'])
                : Http::response(['status' => 'failed', 'error' => 'list index out of range']),
            'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        ]);
        DetectionWordsWriter::fake(fn() => ['words' => ['red wall phone', 'telephone']]);
        Image::fake(fn() => base64_encode(stripedPlace(3)));

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_PENDING,
            'storyline' => ['keyframes' => [['title' => 'One', 'description' => 'He waits.'], ['title' => 'Two', 'description' => 'He calls.']]],
        ]);
        $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
        Keyframe::factory()->for($shot)->create(['position' => 1]);
        $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He calls.']);
        $second->elements()->attach(Element::factory()->object()->for($this->project)->create(['name' => 'Emergency call station']));

        (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

        $render = $second->fresh()->render();

        // Both wordings were tried on the place; what was not found is remembered there.
        expect($render->getCustomProperty(Keyframe::COMPOSITED))->toBeNull()
            ->and($render->getCustomProperty(Keyframe::NOT_COMPOSITED))->toBe('Emergency call station could not be found in the place, so the keyframe is kept as it was drawn.')
            ->and(array_values($shot->getFirstMedia(Shot::PLATE)->getCustomProperty(PlaceObjects::MASKS)))->toBe([false]);
        Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'telephone');
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

it('skips an object that is only carried into the place, instead of finding something that looks like it', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => match (true) {
            $request['version'] === $people => 'https://replicate.delivery/people.png',
            ($request['input']['text_prompt'] ?? null) === Cutout::PERSON => 'https://replicate.delivery/person.png',
            default => 'https://replicate.delivery/thing.png',
        }]),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/person.png' => Http::response(rectangleMask(60, 100, 80, 180)),
        // The look-alike the finder would come up with, right beside the person.
        'replicate.delivery/thing.png' => Http::response(rectangleMask(82, 120, 100, 140)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => false]);
    DetectionWordsWriter::fake(fn() => ['words' => ['clear badge holder']]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));
    Config::set('pipeline.keyframes.drift.enabled', false);

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [['title' => 'One', 'description' => 'He waits.'], ['title' => 'Two', 'description' => 'He holds up the badge holder.']]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He holds up the badge holder.']);
    $holder = Element::factory()->object()->for($this->project)->create(['name' => 'Broken badge holder']);
    $holder->addMediaFromString(stripedPlace())->usingFileName('holder.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach($holder);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    $place = $shot->getFirstMedia(Shot::PLATE);

    // Put onto the place with only the person; the finder was never asked and the answer is kept on the place.
    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        ->and($place->getCustomProperty(PlaceObjects::PRESENT))->toBe([(string) $holder->id => false])
        ->and($place->getCustomProperty(PlaceObjects::MASKS))->toBeNull();
    Http::assertNotSent(fn(HttpRequest $request) => isset($request['input']['text_prompt']) && $request['input']['text_prompt'] !== Cutout::PERSON);
    App\Ai\Agents\ObjectInPlaceJudge::assertPrompted(fn($prompt) => count($prompt->attachments) === 2 && str_contains($prompt->prompt, 'Is Broken badge holder in the place?'));
});

it('keeps an object of the place that someone only stands in front of, and takes it from the drawing when the plan says it is handled', function (array $handled, bool $searched) {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/thing.png']),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        // The bin, right behind the person's legs.
        'replicate.delivery/thing.png' => Http::response(rectangleMask(55, 150, 85, 190)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => true]);
    DetectionWordsWriter::fake(fn() => ['words' => ['waste bin']]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'One', 'description' => 'She waits.', 'handled' => []],
            ['title' => 'Two', 'description' => 'She walks past the Burning waste bin.', 'handled' => $handled],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'She walks past the Burning waste bin.']);
    $bin = Element::factory()->object()->for($this->project)->create(['name' => 'Burning waste bin']);
    $bin->addMediaFromString(stripedPlace())->usingFileName('bin.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach($bin);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue();
    $searched
        ? Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'waste bin')
        : Http::assertNotSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'waste bin');
})->with([
    'only in front of it' => [[], false],
    'handled' => [['Burning waste bin'], true],
]);

it('finds something handled that is not in the place, such as a helicopter flying in, in the drawing itself', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/thing.png']),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/thing.png' => Http::response(rectangleMask(20, 20, 70, 50)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => false]);
    DetectionWordsWriter::fake(fn() => ['words' => ['grey helicopter']]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Far', 'description' => 'The Site helicopter is small over the sea.', 'handled' => ['Site helicopter']],
            ['title' => 'Near', 'description' => 'The Site helicopter hovers above the landing circle.', 'handled' => ['Site helicopter']],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'The Site helicopter hovers above the landing circle.']);
    $helicopter = Element::factory()->object()->for($this->project)->create(['name' => 'Site helicopter']);
    $helicopter->addMediaFromString(stripedPlace())->usingFileName('helicopter.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach($helicopter);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    // On top of the place like the people, so it can be moved like them.
    $movable = imagecreatefromstring(base64_decode((string) $second->fresh()->render()->getCustomProperty(Keyframe::TOP_LAYER_MASK)));
    $scale = imagesx($movable) / 144;

    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        // Not searched in the place, which does not have it: only in the drawing, and not kept on the place.
        ->and($shot->getFirstMedia(Shot::PLATE)->getCustomProperty(PlaceObjects::MASKS))->toBeNull()
        ->and(imagecolorat($movable, (int) (45 * $scale), (int) (35 * $scale)) & 0xFF)->toBeGreaterThan(200)
        ->and(imagecolorat($movable, (int) (10 * $scale), (int) (240 * $scale)) & 0xFF)->toBeLessThan(50);
    Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'grey helicopter');
});

it('takes what a keyframe lists but the place lacks from the drawing, also when it is not handled, such as a helicopter landed in a sequence that sees it take off later', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => $request['version'] === $people ? 'https://replicate.delivery/people.png' : 'https://replicate.delivery/thing.png']),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/thing.png' => Http::response(rectangleMask(20, 20, 70, 50)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => false]);
    DetectionWordsWriter::fake(fn() => ['words' => ['grey helicopter']]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Landed', 'description' => 'The Site helicopter stands on the landing circle.', 'handled' => []],
            ['title' => 'Still landed', 'description' => 'The Site helicopter stands on the landing circle.', 'handled' => []],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'The Site helicopter stands on the landing circle.']);
    $helicopter = Element::factory()->object()->for($this->project)->create(['name' => 'Site helicopter']);
    $helicopter->addMediaFromString(stripedPlace())->usingFileName('helicopter.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach($helicopter);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    // On top of the place like the people, so it can be moved like them.
    $movable = imagecreatefromstring(base64_decode((string) $second->fresh()->render()->getCustomProperty(Keyframe::TOP_LAYER_MASK)));
    $scale = imagesx($movable) / 144;

    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        // Not searched in the place, which does not have it: only in the drawing, and not kept on the place.
        ->and($shot->getFirstMedia(Shot::PLATE)->getCustomProperty(PlaceObjects::MASKS))->toBeNull()
        ->and(imagecolorat($movable, (int) (45 * $scale), (int) (35 * $scale)) & 0xFF)->toBeGreaterThan(200)
        ->and(imagecolorat($movable, (int) (10 * $scale), (int) (240 * $scale)) & 0xFF)->toBeLessThan(50);
    Http::assertSent(fn(HttpRequest $request) => ($request['input']['text_prompt'] ?? null) === 'grey helicopter');
});

it('keeps things that move out of the empty place and has the plan mark them as handled', function () {
    $shot = Shot::factory()->for($this->project)->create(['storyline' => ['keyframes' => [['title' => 'Far', 'description' => 'The helicopter is small over the sea.']]]])->load('project');

    expect(App\Ai\Briefs\KeyframeImageBrief::plate($shot, new App\Ai\KeyframeReferences(), 0))->toContain('anything that moves during the shot, such as a vehicle, a helicopter, a forklift or a hanging load, also when step 1 shows it')
        ->toContain('stands lined up with its lane or road, its nose pointing the way it drives next')
        ->and((string) (new StorylineWriter($shot))->instructions())->toContain('and the things that move by themselves in this shot, such as a helicopter that flies in')
        ->toContain('A thing that stands still throughout this shot, such as a helicopter that has landed, belongs to the place and is not handled');
});

it('does not let a handled thing that is part of the place be moved by hand, since the place would still show it', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => Http::response(['status' => 'succeeded', 'output' => match (true) {
            $request['version'] === $people => 'https://replicate.delivery/people.png',
            ($request['input']['text_prompt'] ?? null) === 'wall phone' => 'https://replicate.delivery/thing.png',
            ($request['input']['text_prompt'] ?? null) === App\Support\Images\Cutout::PERSON => 'https://replicate.delivery/person.png',
            default => 'https://replicate.delivery/nothing.png',
        }]),
        'replicate.delivery/people.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 60, 100, 80, 180, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/person.png' => Http::response(rectangleMask(60, 100, 80, 180)),
        'replicate.delivery/thing.png' => Http::response(rectangleMask(20, 20, 50, 60)),
        'replicate.delivery/nothing.png' => Http::response(rectangleMask(0, 0, 0, 0)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => true]);
    DetectionWordsWriter::fake(fn() => ['words' => ['wall phone']]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Waits', 'description' => 'He waits by the phone.', 'handled' => []],
            ['title' => 'Lifts', 'description' => 'He lifts the Wall phone off its hook.', 'handled' => ['Wall phone']],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He lifts the Wall phone off its hook.']);
    $phone = Element::factory()->object()->for($this->project)->create(['name' => 'Wall phone']);
    $phone->addMediaFromString(stripedPlace())->usingFileName('phone.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach([$phone->id, Element::factory()->for($this->project)->create(['name' => 'Caller', 'type' => 'person'])->id]);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    $movable = imagecreatefromstring(base64_decode((string) $second->fresh()->render()->getCustomProperty(Keyframe::TOP_LAYER_MASK)));
    $scale = imagesx($movable) / 144;

    // Taken from the drawing for the new state, but not movable: only the person is.
    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        ->and(imagecolorat($movable, (int) (30 * $scale), (int) (30 * $scale)) & 0xFF)->toBeLessThan(50)
        ->and(imagecolorat($movable, (int) (70 * $scale), (int) (140 * $scale)) & 0xFF)->toBeGreaterThan(200);
});

it('takes a handled thing of the place from the drawing also where it moved to, such as a truck that drives off', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    $found = 0;
    Http::fake([
        // Found in the place at its spot first, then in the drawing further down, where it drove to.
        'api.replicate.com/v1/predictions' => function (HttpRequest $request) use ($people, &$found) {
            return Http::response(['status' => 'succeeded', 'output' => match (true) {
                $request['version'] === $people => 'https://replicate.delivery/front.png',
                ($request['input']['text_prompt'] ?? null) === 'grey truck' => ++$found === 1 ? 'https://replicate.delivery/at-spot.png' : 'https://replicate.delivery/moved.png',
                default => 'https://replicate.delivery/nothing.png',
            }]);
        },
        'replicate.delivery/front.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 20, 150, 120, 220, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/at-spot.png' => Http::response(rectangleMask(20, 20, 60, 60)),
        'replicate.delivery/moved.png' => Http::response(rectangleMask(20, 150, 120, 220)),
        'replicate.delivery/nothing.png' => Http::response(rectangleMask(0, 0, 0, 0)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => true]);
    DetectionWordsWriter::fake(fn() => ['words' => ['grey truck']]);
    Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Waits', 'description' => 'The truck waits.', 'handled' => ['Delivery truck']],
            ['title' => 'Drives', 'description' => 'The Delivery truck drives towards the camera.', 'handled' => ['Delivery truck']],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'The Delivery truck drives towards the camera.']);
    $truck = Element::factory()->object()->for($this->project)->create(['name' => 'Delivery truck']);
    $truck->addMediaFromString(stripedPlace())->usingFileName('truck.png')->toMediaCollection(Element::REFERENCE);
    $second->elements()->attach($truck);

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    $render = $second->fresh()->render();
    $image = Storage::disk($render->disk)->get($render->getPathRelativeToRoot());

    // Its old spot and where it drove to both come from the drawing; the rest stays the place.
    expect($render->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        ->and(colourAt($image, 40, 40))->toBe([250, 200, 0])
        ->and(colourAt($image, 70, 185))->toBe([250, 200, 0])
        ->and(colourAt($image, 120, 20))->toBe(colourAt(stripedPlace(), 120, 20));
});

it('takes a moving part of the place, such as a barrier arm going up, from the drawing where it was and where it is now', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    $found = 0;
    Http::fake([
        // Found in the place at its spot first, then in the drawing further down, where it drove to.
        'api.replicate.com/v1/predictions' => function (HttpRequest $request) use ($people, &$found) {
            return Http::response(['status' => 'succeeded', 'output' => match (true) {
                $request['version'] === $people => 'https://replicate.delivery/front.png',
                ($request['input']['text_prompt'] ?? null) === 'barrier arm' => ++$found === 1 ? 'https://replicate.delivery/at-spot.png' : 'https://replicate.delivery/moved.png',
                default => 'https://replicate.delivery/nothing.png',
            }]);
        },
        'replicate.delivery/front.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 20, 150, 120, 220, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
        'replicate.delivery/at-spot.png' => Http::response(rectangleMask(20, 20, 60, 60)),
        'replicate.delivery/moved.png' => Http::response(rectangleMask(20, 150, 120, 220)),
        'replicate.delivery/nothing.png' => Http::response(rectangleMask(0, 0, 0, 0)),
    ]);
    App\Ai\Agents\ObjectInPlaceJudge::fake(fn() => ['present' => true]);
    DetectionWordsWriter::fake(fn() => ['words' => ['barrier arm']]);
    Image::fake(fn() => base64_encode(compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 200, 0)))));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Closed', 'description' => 'The barrier is down.', 'handled' => []],
            ['title' => 'Opens', 'description' => 'The barrier arm swings up.', 'handled' => [], 'moving_parts' => ['barrier arm']],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'The barrier arm swings up.']);
    $second->elements()->attach(Element::factory()->for($this->project)->create(['name' => 'Gate guard', 'type' => 'person']));

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    $render = $second->fresh()->render();
    $image = Storage::disk($render->disk)->get($render->getPathRelativeToRoot());

    // Where the arm was and where it is now both come from the drawing; the rest stays the place.
    expect($render->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        ->and(colourAt($image, 40, 40))->toBe([250, 200, 0])
        ->and(colourAt($image, 70, 185))->toBe([250, 200, 0])
        ->and(colourAt($image, 120, 20))->toBe(colourAt(stripedPlace(), 120, 20));
});

it('finds no people when the finder finds nobody, even when the cut-out keeps something large in front', function () {
    Config::set('services.replicate.token', 'test-token');
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake([
        'api.replicate.com/v1/predictions' => fn(HttpRequest $request) => $request['version'] === $people
            ? Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/front.png'])
            : Http::response(['status' => 'failed', 'error' => 'list index out of range']),
        // A parked helicopter, kept in front by the cut-out model.
        'replicate.delivery/front.png' => Http::response(compositePng(fn(GdImage $image) => imagefilledrectangle($image, 20, 60, 120, 140, (int) imagecolorallocatealpha($image, 255, 255, 255, 0)), alpha: true)),
    ]);

    $mask = imagecreatefromstring(app(App\Support\Images\Cutout::class)->people(stripedPlace()));

    expect(imagecolorat($mask, 70, 100) & 0xFF)->toBe(0);
});

it('cuts out nobody in a keyframe whose plan has no people, so a parked helicopter cannot be moved as one', function () {
    Config::set('pipeline.keyframes.composite.enabled', true);
    Config::set('services.replicate.token', 'test-token');
    Config::set('pipeline.keyframes.drift.enabled', false);
    $people = (string) Config::get('pipeline.keyframes.composite.people_model');
    Http::fake(['api.replicate.com/*' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/front.png'])]);
    Image::fake(fn() => base64_encode(stripedPlace(3)));

    $shot = Shot::factory()->for($this->project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'storyline' => ['keyframes' => [
            ['title' => 'Parked', 'description' => 'The helicopter stands on the circle.', 'handled' => []],
            ['title' => 'Still parked', 'description' => 'The helicopter stands on the circle.', 'handled' => []],
        ]],
    ]);
    $shot->addMediaFromString(stripedPlace())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1]);
    $second = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'The helicopter stands on the circle.']);
    $second->elements()->attach(Element::factory()->for($this->project)->create(['name' => 'Flight deck', 'type' => 'place']));

    (new GenerateRemainingKeyframes($shot, elementsDrawn: true))->handle(app(KeyframePainter::class));

    $movable = imagecreatefromstring(base64_decode((string) $second->fresh()->render()->getCustomProperty(Keyframe::TOP_LAYER_MASK)));

    expect($second->fresh()->render()->getCustomProperty(Keyframe::COMPOSITED))->toBeTrue()
        ->and(imagecolorat($movable, 100, 300) & 0xFF)->toBe(0);
    Http::assertNotSent(fn(HttpRequest $request) => ($request['version'] ?? null) === $people);
});

it('leaves the picture of what moves in the shot out of its place, so it is not drawn in', function () {
    Image::fake(fn() => base64_encode(stripedPlace()));
    $shot = Shot::factory()->for($this->project)->create(['storyline' => ['keyframes' => [
        ['title' => 'Waits', 'description' => 'The Delivery truck waits at the Waste bin.', 'elements' => ['Delivery truck', 'Waste bin'], 'handled' => ['Delivery truck']],
        ['title' => 'Drives', 'description' => 'The Delivery truck drives off.', 'elements' => ['Delivery truck', 'Waste bin'], 'handled' => ['Delivery truck']],
    ]]])->load('project');

    foreach (['Delivery truck', 'Waste bin'] as $name) {
        Element::factory()->object()->for($this->project)->create(['name' => $name])->addMediaFromString(stripedPlace())->usingFileName('ref.png')->toMediaCollection(Element::REFERENCE);
    }

    $option = app(KeyframePainter::class)->paintPlateOption($shot, 0);

    expect($option->getCustomProperty(Keyframe::SENT)['images'])->toContain('Picture of Waste bin')
        ->not->toContain('Picture of Delivery truck');
});

it('takes the shadow a person throws out of the picture with them, also where it falls further away', function () {
    // The picture: a red person with a long shadow to their left; the edit: the floor without either, a little off in colour.
    $picture = compositePng(function (GdImage $image) {
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 160, 160, 170));
        imagefilledrectangle($image, 100, 100, 115, 200, (int) imagecolorallocate($image, 220, 40, 40));
        imagefilledrectangle($image, 20, 180, 99, 220, (int) imagecolorallocate($image, 96, 96, 102));
    });
    $emptied = compositePng(fn(GdImage $image) => imagefill($image, 0, 0, (int) imagecolorallocate($image, 158, 158, 168)));
    $person = rectangleMask(100, 100, 115, 200);

    $result = app(App\Support\Images\PlaceComposite::class)->withoutPeople($picture, $emptied, $person);

    // Gone where they stood and where their shadow fell; the floor elsewhere is the picture's own.
    expect(colourAt($result, 107, 150))->toEqualWithDelta([158, 158, 168], 6)
        ->and(colourAt($result, 40, 200))->toEqualWithDelta([158, 158, 168], 8)
        ->and(colourAt($result, 30, 40))->toBe([160, 160, 170]);
});
