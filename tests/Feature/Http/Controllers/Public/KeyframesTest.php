<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\TweakInterpreter;
use App\Ai\KeyframePainter;
use App\Enums\CorrectionSource;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\AdjustPlateOption;
use App\Jobs\CheckKeyframe;
use App\Jobs\ClassifyCorrection;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\GenerateKeyframeOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GeneratePlateOption;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\ReviewShot;
use App\Jobs\TweakKeyframeImage;
use App\Models\Correction;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Decisions\ShotIssues;
use App\Support\Images\OpenRouterImageClient;
use Illuminate\Bus\PendingBatch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function plannedKeyframes(): array
{
    return [
        ['title' => 'At the mailbox', 'description' => 'A man in a navy suit stands at a red mailbox holding a white envelope.'],
        ['title' => 'Posting', 'description' => 'A man in a navy suit pushes a white envelope into the slot of a red mailbox.'],
        ['title' => 'Thumbs up', 'description' => 'A man in a navy suit gives a thumbs up next to a red mailbox.'],
    ];
}

function fakePng(): string
{
    $image = imagecreatetruecolor(16, 9);

    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

/**
 * A picture with vertical stripes, moved `$shift` pixels to the right, so a
 * moved background can be measured. With `$block` a large grey block covers
 * the lower part, like a load lowered on purpose.
 */
function stripedPng(int $shift = 0, bool $block = false): string
{
    $image = imagecreatetruecolor(144, 256);
    $white = imagecolorallocate($image, 255, 255, 255);

    for ($x = 0; $x < 144; $x++) {
        if (intdiv($x + 144 - $shift, 12) % 2 === 0) {
            imageline($image, $x, 0, $x, 255, $white);
        }
    }

    if ($block) {
        imagefilledrectangle($image, 0, 160, 143, 255, (int) imagecolorallocate($image, 128, 128, 128));
    }

    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

/**
 * Runs the whole render: options for keyframe 1, the director picks the first, then the rest.
 */
function renderAllKeyframes(Shot $shot): void
{
    drawFirstKeyframeOptions($shot);

    $first = $shot->keyframes()->firstOrFail();
    $first->forceFill(['render_id' => $first->renders()->first()->id])->save();

    (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));
}

/**
 * Draws the options for keyframe 1. The options run as a batch, which a faked
 * queue only records; then they are drawn here one by one.
 */
function drawFirstKeyframeOptions(Shot $shot, bool $more = false): void
{
    (new GenerateKeyframes($shot, $more))->handle();

    $first = $shot->keyframes()->firstOrFail();

    if ($first->renders()->isEmpty()) {
        foreach (range(0, GenerateKeyframes::optionCount() - 1) as $index) {
            (new GenerateKeyframeOption($first, $index))->handle(app(KeyframePainter::class));
        }

        GenerateKeyframes::finishOptions($shot->id);
    }
}

/**
 * A distinct small PNG per call, so references can be told apart.
 */
function numberedPng(): string
{
    static $count = 0;
    $count++;

    $image = imagecreatetruecolor(16 + $count, 9);

    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

function plannedShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'chosen_storyline' => ['title' => 'Straightforward', 'storyline' => 'He posts the letter.'],
        'storyline' => ['keyframes' => plannedKeyframes()],
        ...$attributes,
    ]);
}

describe('job', function () {
    it('renders an image for every planned keyframe', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        $shot->refresh();
        $keyframes = $shot->keyframes()->get();

        expect($shot->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($shot->storyline_error)->toBeNull()
            ->and($keyframes)->toHaveCount(3)
            ->and($keyframes->pluck('title')->all())->toBe(['At the mailbox', 'Posting', 'Thumbs up'])
            ->and($keyframes->pluck('position')->all())->toBe([1, 2, 3])
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->rendering))->toBeTrue()
            ->and($keyframes->first()->prompt)->toContain('A man in a navy suit stands at a red mailbox')
            ->and($keyframes->first()->generations()->where('kind', 'image')->count())->toBe(GenerateKeyframes::optionCount())
            ->and($keyframes->get(1)->generations()->where('kind', 'image')->count())->toBe(1);

        Storage::disk(Disk::TENANT->value)->assertExists($keyframes->first()->render()->getPathRelativeToRoot());
    });

    it('sends the project style and the shot aspect ratio to the image model', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->size === $shot->aspectRatio()->value
            && $prompt->quality === 'low'
            && $prompt->contains('Visual style:')
            && $prompt->contains('Framing: Full shot at eye level')
            && $prompt->contains('never put a separate wall, panel or backdrop in front of the place')
            && $prompt->contains('A man in a navy suit stands at a red mailbox'));
    });

    it('uses keyframe 1 and the keyframe before as references for the following keyframes', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        [$first, $second] = $shot->keyframes()->with('media')->get();
        $bytes = fn(Keyframe $keyframe) => Storage::disk(Disk::TENANT->value)->get($keyframe->render()->getPathRelativeToRoot());

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox') && $prompt->attachments->isEmpty());
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('Edit the first attached image. It is keyframe 1 of this shot')
            && ! $prompt->contains('directly before this one')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('gives a thumbs up')
            && $prompt->contains('Edit the first attached image. It is keyframe 1 of this shot')
            && $prompt->contains('second attached image is the keyframe directly before this one')
            && $prompt->attachments->count() === 2
            && $prompt->attachments->first()->content() === $bytes($first)
            && $prompt->attachments->last()->content() === $bytes($second));
    });

    it('attaches the pinned style sheet to keyframe 1 only; later keyframes take the style from keyframe 1', function () {
        Image::fake(fn() => fakePng());

        $this->project
            ->addMediaFromString(base64_decode(fakePng()))
            ->usingFileName('style-sheet.png')
            ->toMediaCollection(Project::STYLE_REFERENCES);

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox')
            && $prompt->contains('first attached image is the project\'s style reference sheet')
            && ! $prompt->contains('keyframe 1 of this shot')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && ! $prompt->contains('style reference sheet')
            && $prompt->contains('Edit the first attached image. It is keyframe 1 of this shot')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('gives a thumbs up')
            && $prompt->contains('second attached image is the keyframe directly before this one')
            && $prompt->attachments->count() === 2);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        (new GenerateKeyframeImage($keyframe))->handle(app(KeyframePainter::class));

        expect($keyframe->renders())->toHaveCount(2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope') && $prompt->attachments->count() === 1);
    });

    it('adds someone who walks in later to keyframe 1 and takes their look from their picture and the keyframe before', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        Keyframe::factory()->for($shot)->create(['position' => 4]);
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->where('position', '>', 1)->get()->each(fn(Keyframe $keyframe) => $keyframe->elements()->attach($engineer));

        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $painter = app(KeyframePainter::class);
        $second = $painter->referencesFor($siblings->firstWhere('position', 2), $siblings);
        $fourth = $painter->referencesFor($siblings->firstWhere('position', 4), $siblings);

        expect($second->firstShowsCast)->toBeFalse()
            ->and($second->castNames)->toBe(['Female engineer'])
            ->and($second->style)->toBeNull()
            ->and($second->images()[0]->path)->toBe($siblings->firstWhere('position', 1)->render()->getPathRelativeToRoot())
            ->and($fourth->previous?->path)->toBe($siblings->firstWhere('position', 3)->render()->getPathRelativeToRoot());
    });

    it('checks the place against keyframe 1, the people against their picture and the objects against the keyframe before', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $fourth = Keyframe::factory()->for($shot)->create(['position' => 4]);
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->where('position', '>', 1)->get()->each(fn(Keyframe $keyframe) => $keyframe->elements()->attach($engineer));

        (new GenerateKeyframeImage($shot->keyframes()->where('position', 2)->firstOrFail()))->handle(app(KeyframePainter::class));
        (new GenerateKeyframeImage($fourth))->handle(app(KeyframePainter::class));

        // Keyframe 2: she is not in keyframe 1, which is only the reference for the place.
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Image 1: keyframe 1 of the shot: the reference for the place and the camera; Female engineer is not in it yet.')
            && str_contains((string) $prompt->agent->instructions(), 'Image 2: the keyframe to check.')
            && $prompt->attachments->count() === 2);
        // Keyframe 4 compares the objects and how she looks in this shot with keyframe 3.
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Image 2: the keyframe directly before: the reference for the state of objects, such as what is held or what is in a box, and for how the people look in this shot.')
            && str_contains((string) $prompt->agent->instructions(), 'Image 3: the keyframe to check.')
            && $prompt->attachments->count() === 3);
    });

    it('checks a keyframe after it is shown, keeping only what matters as a note, without redrawing', function () {
        Queue::fake([CheckKeyframe::class]);
        Config::set(['pipeline.keyframe_check' => true, 'pipeline.models.keyframe_check' => 'check/model']);
        Image::fake(fn() => fakePng());
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        // Shown straight away: checking does not hold the keyframe up or block changes.
        Queue::assertPushed(CheckKeyframe::class, fn(CheckKeyframe $job) => $job->keyframe->is($second) && $job->renderId === $second->render()->id);
        expect($second->fresh()->render_stage)->toBe(Keyframe::STAGE_CHECKING)
            ->and($second->fresh()->rendering)->toBeFalse();

        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => [
            ['category' => 'person', 'change' => 'She faces the camera instead of the door.', 'severity' => 'high'],
            ['category' => 'object', 'change' => 'The cup is a little smaller.', 'severity' => 'low'],
        ]]);

        (new CheckKeyframe($second, $second->render()->id))->handle(app(KeyframePainter::class));

        expect($second->fresh()->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['She faces the camera instead of the door.'])
            ->and($second->fresh()->render_stage)->toBeNull()
            ->and($second->fresh()->renders())->toHaveCount(1);
        KeyframeChecker::assertPrompted(fn($prompt) => $prompt->model === 'check/model');
    });

    it('does not check the place of a keyframe put onto its place', function () {
        $keyframe = Keyframe::factory()->for(plannedShot($this->project))->create();

        expect((string) (new KeyframeChecker($keyframe, ['the place', 'the keyframe to check'], placeIsFixed: true))->instructions())
            ->toContain('the place is pasted in from the same picture in every keyframe, so it is always right')
            ->and((string) (new KeyframeChecker($keyframe, ['keyframe 1', 'the keyframe to check']))->instructions())
            ->toContain('The camera stands still: the place does not move between keyframes.');
    });

    it('draws later keyframes on the place without people when keyframe 1 shows someone', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();
        $first->forceFill(['render_id' => $first->renders()->first()->id])->save();
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->elements()->attach($engineer));

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        $plate = $shot->fresh()->getFirstMedia(Shot::PLATE);

        expect($plate)->not->toBeNull()
            ->and($plate->getCustomProperty(Shot::PLATE_FROM))->toBe($first->fresh()->render()->id);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('remove every person from it') && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('It is the place of this shot without any people')
            && $prompt->contains('Female engineer is not in the first image yet: add them into it')
            && $prompt->attachments->first()->path === $plate->getPathRelativeToRoot()
            && $prompt->contains('attached image is the keyframe directly before this one'));

        // The plate is made once for this version of keyframe 1.
        (new GenerateKeyframeImage($shot->keyframes()->where('position', 2)->firstOrFail()))->handle(app(KeyframePainter::class));

        expect($shot->fresh()->getMedia(Shot::PLATE))->toHaveCount(1)
            ->and($shot->generations()->where('prompt', 'like', '%remove every person%')->count())->toBe(1);
    });

    it('uses keyframe 1 as it is when it shows no people', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        expect($shot->fresh()->getFirstMedia(Shot::PLATE))->toBeNull();
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('remove every person from it'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('It is keyframe 1 of this shot'));
    });

    it('starts from three empty places drawn from the whole plan, and draws every keyframe on the chosen one', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
        Image::fake(fn() => fakePng());
        Bus::fake([GeneratePlateOption::class]);

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();

        Bus::assertBatched(fn(PendingBatch $batch) => $batch->jobs->count() === 3 && $batch->jobs->every(fn($job) => $job instanceof GeneratePlateOption));

        foreach (range(0, 2) as $variation) {
            (new GeneratePlateOption($shot, $variation))->handle(app(KeyframePainter::class));
        }
        GenerateKeyframes::finishPlates($shot->id);

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($shot->getMedia(Shot::PLATE_OPTIONS))->toHaveCount(3);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Draw the place where this shot plays, empty: no people at all.')
            && $prompt->contains('1. A man in a navy suit stands at a red mailbox holding a white envelope.')
            && $prompt->contains('Leave free floor where the people will stand and walk'));

        Queue::fake([GenerateRemainingKeyframes::class]);
        $option = $shot->getMedia(Shot::PLATE_OPTIONS)->get(1);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plate.choose', [$this->project, $shot]), ['plate' => $option->id])
            ->assertSessionHasNoErrors();

        $plate = $shot->fresh()->getFirstMedia(Shot::PLATE);

        // Keyframe 1 shows no people here, so it is the chosen place as it is, and the others start straight away.
        expect($plate->getCustomProperty(Shot::PLATE_CHOSEN))->toBeTrue()
            ->and($shot->keyframes()->with('media')->first()->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['The chosen place'])
            ->and($shot->keyframes()->where('rendering', true)->count())->toBe($shot->keyframes()->count() - 1)
            ->and($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING);
        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => ! $job->onlyFirst);

        (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class));

        $keyframes = $shot->keyframes()->with('media')->get();

        expect($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->rendering))->toBeTrue();
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('It is the place of this shot without any people')
            && $prompt->attachments->first()->path === $plate->getPathRelativeToRoot());
    });

    it('draws only keyframe 1 on the chosen place when it shows people, and the others once it is confirmed', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
        Image::fake(fn() => fakePng());
        Bus::fake([GeneratePlateOption::class]);

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();
        (new GeneratePlateOption($shot, 0))->handle(app(KeyframePainter::class));
        GenerateKeyframes::finishPlates($shot->id);
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->elements()->attach($engineer));

        Queue::fake([GenerateRemainingKeyframes::class]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plate.choose', [$this->project, $shot]), ['plate' => $shot->fresh()->getFirstMedia(Shot::PLATE_OPTIONS)->id])
            ->assertSessionHasNoErrors();

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->keyframes()->where('rendering', true)->pluck('position')->all())->toBe([1]);
        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->onlyFirst);

        (new GenerateRemainingKeyframes($shot->fresh(), elementsDrawn: true, onlyFirst: true))->handle(app(KeyframePainter::class));

        $keyframes = $shot->keyframes()->with('media')->get();

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($keyframes->firstWhere('position', 1)->render())->not->toBeNull()
            ->and($keyframes->where('position', '>', 1)->every(fn(Keyframe $keyframe) => $keyframe->render() === null))->toBeTrue()
            // The chat asks to confirm it.
            ->and(collect($shot->fresh()->plan_chat)->last()['text'])->toBe('Keyframe 1 is ready. Do you want this one? Click it or say yes, or tell me what to do differently.');

        // The decision is to confirm keyframe 1, not to choose a place again.
        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $keyframes->first()->render()->id])
            ->assertSessionHasNoErrors();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($shot->keyframes()->where('rendering', true)->count())->toBe($keyframes->count() - 1);
    });

    it('adjusts a place before it is chosen and adds the result as a new place', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
        Image::fake(fn() => fakePng());
        Bus::fake([GeneratePlateOption::class]);

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();
        (new GeneratePlateOption($shot, 0))->handle(app(KeyframePainter::class));
        GenerateKeyframes::finishPlates($shot->id);
        $option = $shot->fresh()->getFirstMedia(Shot::PLATE_OPTIONS);

        Queue::fake([AdjustPlateOption::class]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plate.adjust', [$this->project, $shot]), ['plate' => $option->id, 'instruction' => 'Put the bin left of the door.'])
            ->assertSessionHasNoErrors();

        expect($shot->keyframes()->where('position', 1)->value('rendering'))->toBeTrue();
        Queue::assertPushed(AdjustPlateOption::class, fn(AdjustPlateOption $job) => $job->option === $option->id && $job->instruction === 'Put the bin left of the door.');

        // A place cannot be chosen while the adjusted one is drawn.
        actingAs($this->director, 'director')
            ->post(route('public.shots.plate.choose', [$this->project, $shot]), ['plate' => $option->id])
            ->assertSessionHasErrors('plate');

        (new AdjustPlateOption($shot, $option->id, 'Put the bin left of the door.'))->handle(app(KeyframePainter::class));

        $places = $shot->fresh()->getMedia(Shot::PLATE_OPTIONS);

        expect($places)->toHaveCount(2)
            ->and($places->last()->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('Put the bin left of the door.')
            ->and($shot->keyframes()->where('position', 1)->value('rendering'))->toBeFalse()
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: Put the bin left of the door.')
            && $prompt->contains('Do not add people.')
            && $prompt->attachments->first()->path === $option->getPathRelativeToRoot());
    });

    it('goes back to the places while keyframe 1 on the chosen one waits', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
        Image::fake(fn() => fakePng());
        Bus::fake([GeneratePlateOption::class]);

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();
        (new GeneratePlateOption($shot, 0))->handle(app(KeyframePainter::class));
        GenerateKeyframes::finishPlates($shot->id);
        $option = $shot->fresh()->getFirstMedia(Shot::PLATE_OPTIONS);
        $option->copy($shot, Shot::PLATE)->setCustomProperty(Shot::PLATE_CHOSEN, true)->save();
        $first = $shot->keyframes()->firstOrFail();
        $option->copy($first, Keyframe::RENDERS);

        actingAs($this->director, 'director')
            ->post(route('public.shots.plate.reset', [$this->project, $shot]))
            ->assertSessionHasNoErrors();

        expect($shot->fresh()->hasChosenPlate())->toBeFalse()
            ->and($shot->fresh()->getMedia(Shot::PLATE_OPTIONS))->toHaveCount(1)
            ->and($first->fresh()->renders())->toBeEmpty()
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);
    });

    it('draws a keyframe again when its background moved compared with the image it was drawn on', function () {
        $calls = 0;
        // Three options for keyframe 1, then keyframe 2 moves once before it lines up, then keyframe 3.
        Image::fake(function () use (&$calls) {
            $calls++;

            return stripedPng($calls === 4 ? 6 : 0);
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        expect($calls)->toBe(6)
            ->and($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::STILLNESS))->toEqual(1.0)
            ->and($second->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeNull();
    });

    it('keeps a keyframe where something changed on purpose, since no shift or zoom lines it up better', function () {
        $calls = 0;
        Image::fake(function () use (&$calls) {
            $calls++;

            return stripedPng(block: $calls > 3);
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        expect($calls)->toBe(5)
            ->and($second->render()->getCustomProperty(Keyframe::STILLNESS))->toBeLessThan(0.85)
            ->and($second->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeNull();
    });

    it('adjusts a keyframe drawn on a place by editing the place again, with the current version for the people', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();
        $plate = $second->render()->copy($shot, Shot::PLATE);
        $plate->setCustomProperty(Shot::PLATE_CHOSEN, true)->save();

        (new TweakKeyframeImage($second, 'he looks at the camera'))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Edit the first attached image. It is the place of this shot without any people')
            && $prompt->contains('The second attached image is the current version of this keyframe.')
            && $prompt->contains('Change only this compared with the current version: he looks at the camera')
            && $prompt->attachments->first()->path === $plate->getPathRelativeToRoot()
            && $prompt->attachments->count() === 3);
        expect($second->fresh()->render()->getCustomProperty(Keyframe::SENT)['images'])->toBe(['The place without people, the image that is edited', 'This version, for the people and what changed', 'The keyframe before']);
    });

    it('keeps the stillest attempt and notes it when the background keeps moving, and Fix draws it again', function () {
        $calls = 0;
        Image::fake(function () use (&$calls) {
            $calls++;

            return stripedPng($calls > 3 ? 6 : 0);
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        $keyframes = $shot->keyframes()->with('media')->get();
        $second = $keyframes->firstWhere('position', 2);

        expect($calls)->toBe(3 + 3 + 3)
            ->and($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeTrue();

        $issues = app(ShotIssues::class);
        $group = collect($issues->groups($shot->fresh(), $keyframes))->firstWhere('key', 'keyframe-2');

        expect($group['issues'])->toContain('The background moved compared with the place, also after drawing it again.');

        Queue::fake([GenerateKeyframeImage::class, TweakKeyframeImage::class]);
        $issues->fix($shot->fresh(), 'keyframe-2');

        Queue::assertPushed(GenerateKeyframeImage::class);
        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect($second->fresh()->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeNull();

        // On a place the moved version goes back onto it as it is, so the pose and the asked changes stay.
        $plate = $keyframes->firstWhere('position', 1)->render()->copy($shot, Shot::PLATE);
        $plate->setCustomProperty(Shot::PLATE_CHOSEN, true)->save();
        $second->fresh()->render()->setCustomProperty(Keyframe::BACKGROUND_MOVED, true)->save();
        $second->newQuery()->whereKey($second->id)->update(['rendering' => false]);
        $issues->fix($shot->fresh(), 'keyframe-2');

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->fromCheck
            && ! $job->rewrite
            && str_contains($job->instruction, 'stay exactly as in the current version; only the background is the place\'s own again.'));
    });

    it('replaces the keyframes of an earlier render', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        $stale = Keyframe::factory()->for($shot)->create(['title' => 'Old keyframe']);

        renderAllKeyframes($shot);

        expect(Keyframe::query()->whereKey($stale->id)->exists())->toBeFalse()
            ->and($shot->keyframes()->count())->toBe(3);
    });

    it('keeps the plan and reports an error when rendering fails', function () {
        Image::fake(fn() => throw new RuntimeException('Provider down'));

        $shot = plannedShot($this->project);
        $job = new GenerateKeyframes($shot);

        expect(fn() => $job->handle(app(KeyframePainter::class)))->toThrow(RuntimeException::class);

        $job->failed(new RuntimeException('Provider down'));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->storyline_error)->not->toBeNull()
            ->and($shot->storylineKeyframes())->toEqual(plannedKeyframes())
            ->and($shot->keyframes()->first()->rendering)->toBeFalse()
            ->and($shot->keyframes()->first()->render_error)->toBe('Provider down')
            ->and($shot->keyframes()->first()->generations()->whereNotNull('error')->count())->toBe(1);
    });
});

describe('first keyframe', function () {
    it('draws the options side by side in one batch', function () {
        Bus::fake();

        $shot = plannedShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);

        (new GenerateKeyframes($shot))->handle();

        $first = $shot->keyframes()->firstOrFail();

        Bus::assertBatched(fn(PendingBatch $batch) => $batch->jobs->count() === GenerateKeyframes::optionCount()
            && $batch->allowsFailures()
            && $batch->jobs->every(fn(GenerateKeyframeOption $job) => $job->keyframe->is($first))
            && $batch->jobs->pluck('variation')->all() === range(0, GenerateKeyframes::optionCount() - 1));
    });

    it('settles the round once the batch is done', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);
        $first = Keyframe::factory()->for($shot)->create(['position' => 1, 'rendering' => true]);

        GenerateKeyframes::finishOptions($shot->id);

        expect($shot->fresh())->status->toBe(ShotStatus::STORYLINE_READY)->storyline_error->not->toBeNull()
            ->and($first->fresh())->rendering->toBeFalse()->render_error->not->toBeNull();

        (new GenerateKeyframeOption($first, 0))->handle(app(KeyframePainter::class));
        GenerateKeyframes::finishOptions($shot->id, failed: 2);

        expect($shot->fresh())->status->toBe(ShotStatus::FIRST_KEYFRAME_READY)->storyline_error->toContain('Not every option')
            ->and($first->fresh())->render_id->toBeNull()->render_error->toBeNull();

        GenerateKeyframes::finishOptions($shot->id);

        expect($shot->fresh()->storyline_error)->toBeNull();
    });

    it('draws a single start frame and waits for the director to confirm it before drawing the rest', function () {
        Config::set('pipeline.keyframes.first_options', 1);
        Queue::fake([GenerateRemainingKeyframes::class]);
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);

        $first = $shot->keyframes()->with('media')->firstOrFail();

        expect($first->renders())->toHaveCount(1)
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);
        Queue::assertNotPushed(GenerateRemainingKeyframes::class);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $first->renders()->first()->id])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->shot->is($shot));
    });

    it('draws options for keyframe 1 only and waits for a choice', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);

        (new GenerateKeyframes($shot))->handle();

        $shot->refresh();
        [$first, $second] = $shot->keyframes()->get();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($shot->keyframes()->count())->toBe(3)
            ->and($first->renders())->toHaveCount(3)
            ->and($first->render_id)->toBeNull()
            ->and($first->rendering)->toBeFalse()
            ->and($second->renders())->toHaveCount(0)
            ->and($second->rendering)->toBeFalse();

        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Stage it as described.'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Choose a different calm part of the same place'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Choose different lighting'));
    });

    it('adjusts one option for keyframe 1 and adds the result as a new option', function () {
        Image::fake(fn() => numberedPng());
        // An option is not the keyframe yet, so its adjustment never rewrites the keyframe's text.
        TweakInterpreter::fake([['instruction' => 'The red line runs across the quay right in front of her feet.', 'description' => 'She stands at the red line.', 'absent' => []]]);

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();
        $option = $first->renders()->get(1);
        $description = $first->description;

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.adjust', [$this->project, $shot]), ['render' => $option->id, 'instruction' => 'Put the line across her path', 'rewrite' => true])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $first->refresh();
        $added = $first->renders()->last();

        expect($first->renders())->toHaveCount(GenerateKeyframes::optionCount() + 1)
            ->and($first->render_id)->toBeNull()
            ->and($first->rendering)->toBeFalse()
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($added->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('Put the line across her path')
            ->and($first->description)->toBe($description);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The red line runs across the quay right in front of her feet.')
            && $prompt->attachments->first()->path === $option->getPathRelativeToRoot());
    });

    it('draws an option again instead of editing it when the change moves people through the scene', function () {
        Config::set('pipeline.models.keyframe', 'create/model');
        Config::set('pipeline.models.keyframe_edit', 'edit/model');
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'She stands right next to the crane, the container almost above her.', 'approach' => 'redraw']]);

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.adjust', [$this->project, $shot]), ['render' => $first->renders()->first()->id, 'instruction' => 'Closer to the crane', 'rewrite' => true])
            ->assertSessionHasNoErrors();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'create/model'
            && $prompt->contains('The director asked for this change, and it matters most; where it differs from the description, spot or keyframe references above, follow the change: She stands right next to the crane')
            && $prompt->contains('A man in a navy suit stands at a red mailbox'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'edit/model');

        expect($first->fresh())->rendering->toBeFalse()->render_id->toBeNull()
            ->and($first->fresh()->renders())->toHaveCount(GenerateKeyframes::optionCount() + 1);
    });

    it('does not choose an option while an adjusted one is being drawn', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();
        $first->forceFill(['rendering' => true])->save();
        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $first->renders()->first()->id])
            ->assertSessionHasErrors('render');

        expect($first->fresh()->render_id)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('only adjusts options while keyframe 1 is waiting for a choice', function () {
        Queue::fake();
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.adjust', [$this->project, $shot]), ['render' => 1, 'instruction' => 'Closer'])
            ->assertSessionHasErrors('instruction');

        Queue::assertNothingPushed();
    });

    it('adds more options on request', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();

        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.more', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
        Queue::assertPushed(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->more);

        (new GenerateKeyframes($shot->fresh(), more: true))->handle();

        expect($shot->keyframes()->firstOrFail()->renders())->toHaveCount(6)
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY);
    });

    it('renders the other keyframes from the chosen option', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();
        $first = $shot->keyframes()->firstOrFail();
        $chosen = $first->renders()->get(1);

        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $chosen->id])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($first->fresh()->render()->id)->toBe($chosen->id)
            ->and($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($shot->keyframes()->where('position', 2)->first()->rendering)->toBeTrue();

        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->shot->is($shot));

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        $chosenBytes = Storage::disk(Disk::TENANT->value)->get($chosen->getPathRelativeToRoot());

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($shot->keyframes()->get()->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->rendering))->toBeTrue();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->content() === $chosenBytes);
    });

    it('rejects an option of another keyframe', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY])->save();
        $other = $shot->keyframes()->where('position', 2)->firstOrFail()->render();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $other->id])
            ->assertSessionHasErrors('render');
    });

    it('only accepts a choice while the first keyframe is waiting for one', function () {
        Queue::fake();

        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => 1])
            ->assertSessionHasErrors('render');

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.more', [$this->project, $shot]))
            ->assertSessionHasErrors('keyframes');

        Queue::assertNothingPushed();
    });

    it('forbids choosing for another director\'s shot', function () {
        $shot = plannedShot(Project::factory()->create(), ['status' => ShotStatus::FIRST_KEYFRAME_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$shot->project, $shot]), ['render' => 1])
            ->assertForbidden();
    });

    it('goes back to choosing when the other keyframes fail', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_PENDING]);
        Keyframe::factory()->for($shot)->create(['position' => 2, 'rendering' => true]);

        (new GenerateRemainingKeyframes($shot))->failed(new RuntimeException('Provider down'));

        expect($shot->fresh())->status->toBe(ShotStatus::FIRST_KEYFRAME_READY)->storyline_error->not->toBeNull()
            ->and($shot->keyframes()->first()->rendering)->toBeFalse();
    });
});

describe('add', function () {
    it('adds a keyframe at the end and renders it to match keyframe 1', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $shot]), ['title' => 'Walks away', 'description' => 'The man walks away from the mailbox, smiling.'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $added = $shot->keyframes()->where('position', 4)->firstOrFail();

        expect($added->title)->toBe('Walks away')
            ->and($added->rendering)->toBeTrue()
            ->and($shot->fresh()->storylineKeyframes()[3])->toEqual(['title' => 'Walks away', 'description' => 'The man walks away from the mailbox, smiling.']);

        Queue::assertPushed(GenerateKeyframeImage::class, fn(GenerateKeyframeImage $job) => $job->keyframe->is($added));

        (new GenerateKeyframeImage($added))->handle(app(KeyframePainter::class));

        expect($added->fresh()->prompt)
            ->toContain('The man walks away from the mailbox, smiling.')
            ->toContain('keyframe 1 of this shot')
            ->toContain('the keyframe directly before this one');
    });

    it('requires a title and a description', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $shot]), ['title' => '', 'description' => ''])
            ->assertSessionHasErrors(['title', 'description']);
    });

    it('only adds keyframes once the shot is rendered and has room', function () {
        Image::fake(fn() => numberedPng());
        Queue::fake();

        $choosing = plannedShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_READY]);
        Keyframe::factory()->for($choosing)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $choosing]), ['title' => 'Extra', 'description' => 'More.'])
            ->assertSessionHasErrors('description');

        config(['pipeline.keyframes.max_manual' => 3]);
        $full = plannedShot($this->project);
        renderAllKeyframes($full);
        $full->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $full]), ['title' => 'Extra', 'description' => 'More.'])
            ->assertSessionHasErrors('description');

        Queue::assertNotPushed(GenerateKeyframeImage::class);
    });

    it('copies a keyframe right after it with its plan, cast and image, without drawing', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();
        Queue::fake();

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();
        $titles = $shot->keyframes()->pluck('title')->all();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.copy', [$this->project, $shot, $second]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $keyframes = $shot->keyframes()->with('media')->get();
        $copy = $keyframes->firstWhere('position', 3);

        expect($keyframes->pluck('title')->all())->toBe([$titles[0], $titles[1], $titles[1], ...array_slice($titles, 2)])
            ->and($copy->is($second))->toBeFalse()
            ->and($copy->description)->toBe($second->description)
            ->and($copy->render())->not->toBeNull()
            ->and($copy->render()->id)->not->toBe($second->fresh()->render()->id)
            ->and($shot->fresh()->storylineKeyframes()[2]['title'])->toBe($titles[1]);
        Queue::assertNotPushed(GenerateKeyframeImage::class);
    });

    it('marks a copy as undescribed so the check and the review do not judge it by the original\'s text', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.copy', [$this->project, $shot, $second]));

        $plans = $shot->fresh()->storylineKeyframes();

        expect($plans[2]['copied'] ?? null)->toBeTrue()
            ->and($plans[1])->not->toHaveKey('copied');

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('keyframes.1.needsDescription', false)
                ->where('keyframes.2.needsDescription', true));

        $keyframes = $shot->keyframes()->with('media')->get();
        expect((new ShotReviewer($shot->fresh(), $keyframes))->promptFor())
            ->toContain('3. A copy of another keyframe that the director has not described yet');
    });

    it('copies a keyframe only once every keyframe is drawn, so running jobs keep their positions', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_PENDING])->save();
        $third = $shot->keyframes()->where('position', 3)->firstOrFail();
        $third->update(['rendering' => true]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.copy', [$this->project, $shot, $third]))
            ->assertSessionHasErrors('keyframe');

        expect($shot->keyframes()->count())->toBe(3);
    });

    it('refuses a copy when the shot is full', function () {
        Image::fake(fn() => fakePng());
        config(['pipeline.keyframes.max_manual' => 3]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.copy', [$this->project, $shot, $shot->keyframes()->firstOrFail()]))
            ->assertSessionHasErrors('keyframe');

        expect($shot->keyframes()->count())->toBe(3);
    });

    it('lets the director add keyframes beyond what the planner plans, up to the manual maximum', function () {
        Queue::fake([GenerateKeyframeImage::class]);
        Image::fake(fn() => fakePng());
        config(['pipeline.keyframes.max' => 3, 'pipeline.keyframes.max_manual' => 4]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.maxKeyframes', 4));

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $shot]), ['title' => 'Extra', 'description' => 'More.'])
            ->assertSessionHasNoErrors();

        expect($shot->keyframes()->count())->toBe(4);
        Queue::assertPushed(GenerateKeyframeImage::class);
    });

    it('forbids adding to another director\'s shot', function () {
        $shot = plannedShot(Project::factory()->create(), ['status' => ShotStatus::KEYFRAMES_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$shot->project, $shot]), ['title' => 'Extra', 'description' => 'More.'])
            ->assertForbidden();
    });
});

describe('update', function () {
    beforeEach(fn() => Queue::fake());

    it('changes the description and renders that keyframe again', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting', 'render_error' => 'Old error']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He drops the envelope in the slot and smiles.'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $keyframe->refresh();

        expect($keyframe->description)->toBe('He drops the envelope in the slot and smiles.')
            ->and($keyframe->rendering)->toBeTrue()
            ->and($keyframe->render_error)->toBeNull()
            ->and($shot->fresh()->storylineKeyframes()[1])->toEqual(['title' => 'Posting', 'description' => 'He drops the envelope in the slot and smiles.']);

        Queue::assertPushed(GenerateKeyframeImage::class, fn(GenerateKeyframeImage $job) => $job->keyframe->is($keyframe));
    });

    it('adjusts the current image to a changed description and keeps the wording', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting', 'description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He drops the envelope in the slot.'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->description)->toBe('He drops the envelope in the slot.');

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->is($keyframe)
            && $job->rewrite
            && $job->describedByDirector
            && str_contains($job->instruction, '"He holds the envelope." to "He drops the envelope in the slot."'));
        Queue::assertNotPushed(GenerateKeyframeImage::class);
    });

    it('draws the keyframe again from its description when asked, also unchanged', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He holds the envelope.', 'redraw' => true])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateKeyframeImage::class, fn(GenerateKeyframeImage $job) => $job->keyframe->is($keyframe));
        Queue::assertNotPushed(TweakKeyframeImage::class);
    });

    it('needs a changed description to adjust the image', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He holds the envelope. '])
            ->assertSessionHasErrors('description');

        Queue::assertNotPushed(TweakKeyframeImage::class);
        Queue::assertNotPushed(GenerateKeyframeImage::class);
        expect($keyframe->fresh()->rendering)->toBeFalse();
    });

    it('drops the copy mark of the old wording', function () {
        $plans = plannedKeyframes();
        $plans[1] = [...$plans[1], 'copied' => true];
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['keyframes' => $plans]]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'The street is empty; he is not there yet.']);

        expect($shot->fresh()->storylineKeyframes()[1])->not->toHaveKey('copied')
            ->and($shot->fresh()->plannedKeyframeIsCopy(2))->toBeFalse();
    });

    it('requires a description', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => ''])
            ->assertSessionHasErrors('description');

        Queue::assertNothingPushed();
    });

    it('forbids changing another director\'s keyframe', function () {
        $shot = plannedShot(Project::factory()->create());
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$shot->project, $shot, $keyframe]), ['description' => 'Changed'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('renders a single keyframe with the first render as reference', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $previous = $keyframe->render()->id;
        $plan = $shot->storylineKeyframes();
        $plan[1]['description'] = 'A man in a navy suit smiles at the mailbox.';
        $shot->forceFill(['storyline' => ['keyframes' => $plan]])->save();
        $keyframe->forceFill(['rendering' => true])->save();

        (new GenerateKeyframeImage($keyframe))->handle(app(KeyframePainter::class));

        $keyframe->refresh();

        expect($keyframe->rendering)->toBeFalse()
            ->and($keyframe->render()->id)->not->toBe($previous)
            ->and($keyframe->renders())->toHaveCount(2);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('smiles at the mailbox')
            && $prompt->contains('keyframe 1 of this shot')
            && ! $prompt->contains('directly before this one')
            && $prompt->attachments->count() === 1);
    });

    it('marks the keyframe when its render fails', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create(['rendering' => true]);

        (new GenerateKeyframeImage($keyframe))->failed(new RuntimeException('Provider down'));

        expect($keyframe->fresh())->rendering->toBeFalse()->render_error->not->toBeNull();
    });
});

describe('tweak', function () {
    beforeEach(function () {
        // By default the rewrite returns the request unchanged, so the existing assertions on the prompt hold.
        TweakInterpreter::fake(fn(AgentPrompt $prompt) => ['instruction' => Str::after($prompt->prompt, "The director's request: ")]);
    });

    it('rewrites the request from the image and shows both on the new version', function () {
        TweakInterpreter::fake([['instruction' => 'The sign is behind him on the left of the frame. He looks back over his right shoulder at it.']]);
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        (new TweakKeyframeImage($keyframe, 'He should look backwards at the sign', rewrite: true))->handle(app(KeyframePainter::class));

        $render = $keyframe->fresh()->render();

        expect($render->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('He should look backwards at the sign')
            ->and($render->getCustomProperty(Keyframe::TWEAK_INSTRUCTION))->toBe('The sign is behind him on the left of the frame. He looks back over his right shoulder at it.');

        TweakInterpreter::assertPrompted(fn(AgentPrompt $prompt) => str_contains($prompt->prompt, 'He should look backwards at the sign')
            && $prompt->attachments->count() === 2
            && str_contains((string) $prompt->agent->instructions(), 'Never write "forward", "backward", "in front of him" or "behind her"')
            && str_contains((string) $prompt->agent->instructions(), 'show them mid-step in the direction they go'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: The sign is behind him on the left of the frame.')
            && ! $prompt->contains('He should look backwards'));

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('keyframes.1.renders.1.request', 'He should look backwards at the sign')
                ->where('keyframes.1.renders.1.requestFromCheck', false)
                ->where('keyframes.1.renders.1.instruction', 'The sign is behind him on the left of the frame. He looks back over his right shoulder at it.')
                ->where('keyframes.1.renders.0.instruction', null));
    });

    it('sends the director\'s adjustment to the image model as typed, unless they ask for it to be made precise', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        TweakInterpreter::fake([['instruction' => 'The helmet on his head is plain blue without any lettering.', 'approach' => 'redraw', 'description' => 'He wears a plain blue helmet.', 'absent' => []]]);

        (new TweakKeyframeImage($keyframe, 'Make his helmet plain blue'))->handle(app(KeyframePainter::class));

        // The text model only brings the description up to date; the image model gets the request as typed, as an edit.
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: Make his helmet plain blue'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('plain blue without any lettering'));
        expect($keyframe->fresh()->description)->toBe('He wears a plain blue helmet.');

        $sent = $keyframe->fresh()->render()->getCustomProperty(Keyframe::SENT);

        expect($sent['prompt'])->toContain('Change only this: Make his helmet plain blue')
            ->and($sent['images'])->toBe(['This version, the image that is edited', 'The keyframe before']);
    });

    it('sends the request as typed when the rewrite fails', function () {
        TweakInterpreter::fake(fn() => throw new RuntimeException('text model down'));
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->firstOrFail();

        (new TweakKeyframeImage($keyframe, 'Make the sign bigger', rewrite: true))->handle(app(KeyframePainter::class));

        expect($keyframe->fresh()->render()->getCustomProperty(Keyframe::TWEAK_INSTRUCTION))->toBe('Make the sign bigger')
            ->and($keyframe->generations()->where('kind', 'text')->latest('id')->first()->error)->toBe('text model down');

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: Make the sign bigger'));
    });

    it('adjusts the current render and keeps the earlier version', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $previous = $keyframe->render()->id;

        (new TweakKeyframeImage($keyframe, 'Remove the lighter from his hand'))->handle(app(KeyframePainter::class));

        $keyframe->refresh();

        expect($keyframe->renders())->toHaveCount(2)
            ->and($keyframe->render()->id)->not->toBe($previous)
            ->and($keyframe->render_id)->toBe($keyframe->render()->id)
            ->and($keyframe->rendering)->toBeFalse()
            ->and($keyframe->prompt)->toContain('pushes a white envelope')
            ->and($keyframe->generations()->latest('id')->first()->prompt)->toContain('Change only this: Remove the lighter from his hand')
            ->and($keyframe->generations()->latest('id')->first()->model)->toBe('openai/gpt-5.4-image-2');

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Remove the lighter from his hand')
            && $prompt->model === 'openai/gpt-5.4-image-2'
            && $prompt->contains('Edit the first attached image.')
            && $prompt->contains('The second attached image is the keyframe directly before this one')
            && $prompt->attachments->count() === 2
            && $prompt->attachments->first()->content() === base64_decode(fakePng()));
    });

    it('creates keyframes with the keyframe model and tweaks with the edit model', function () {
        Config::set('pipeline.models.keyframe', 'create/model');
        Config::set('pipeline.models.keyframe_edit', 'edit/model');
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        (new TweakKeyframeImage($shot->keyframes()->firstOrFail(), 'Look back at the sign', rewrite: true))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'edit/model' && $prompt->contains('Look back at the sign'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'edit/model' && ! $prompt->contains('Look back at the sign'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'create/model');
    });

    it('keeps what the automatic check finds as notes and never redraws by itself', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        $stages = [];
        KeyframeChecker::fake(function () use (&$stages) {
            $stages[] = Keyframe::query()->where('position', 2)->value('render_stage');

            return ['inventory' => [], 'issues' => [
                ['category' => 'place', 'change' => 'The DAMEN logo is missing from the hall.', 'severity' => 'high'],
                ['category' => 'place', 'change' => 'A window is a little narrower.', 'severity' => 'low'],
            ]];
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        expect($stages[0])->toBe(Keyframe::STAGE_CHECKING)
            ->and($second->rendering)->toBeFalse()
            ->and($second->render_stage)->toBeNull()
            ->and($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['The DAMEN logo is missing from the hall.']);
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'What the keyframe to check should show:'));

        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        expect(collect(app(ShotIssues::class)->open($shot->fresh(), $shot->keyframes()->with('media')->get()))->firstWhere('id', 'found-2'))
            ->toMatchArray(['problem' => 'The DAMEN logo is missing from the hall.', 'keyframes' => [2]]);
    });

    it('has the check judge the spatial point of the description, without a separate must show line', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => [['category' => 'place', 'change' => 'She stands far from the container.', 'severity' => 'high']]]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        Image::assertNotGenerated(fn(ImagePrompt $prompt) => str_contains((string) $prompt->prompt, 'Most important, this must be clearly visible'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'The point of the keyframe: what the description says about where people stand')
            && ! str_contains((string) $prompt->agent->instructions(), 'Must show:'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'someone who faces the camera or walks towards it while the description says they go away from it')
            && str_contains((string) $prompt->agent->instructions(), 'Direction: check which way someone faces or moves only when the story depends on it')
            && str_contains((string) $prompt->agent->instructions(), 'lettering that appears, or a floor line that runs elsewhere')
            && str_contains((string) $prompt->agent->instructions(), 'Image 1: keyframe 1 of the shot: the reference for the place and the camera, and for how the people look.'));
    });

    it('reviews all keyframes together against the takeaway and keeps the notes', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        // Keyframe 7 does not exist, so it is dropped.
        ShotReviewer::fake(fn() => ['clear' => false, 'notes' => [['keyframes' => [3, 2, 7], 'note' => 'The envelope looks the same, so posting cannot be seen.']]]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        expect($shot->fresh()->keyframe_review)->toBe(['clear' => false, 'notes' => [['text' => 'The envelope looks the same, so posting cannot be seen.', 'keyframes' => [2, 3]]]]);
        ShotReviewer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'The shot teaches: Sending the letter is easy and final') && $prompt->attachments->count() === 3);
        ShotReviewer::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'recognisable as the same person throughout'));
        ShotReviewer::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Two keyframes in a row that look almost the same')
            && str_contains((string) $prompt->agent->instructions(), 'Someone who enters a place moves into it')
            && str_contains((string) $prompt->agent->instructions(), 'Words, logos or numbers that appear or disappear between keyframes')
            && str_contains((string) $prompt->agent->instructions(), 'a floor line that runs differently is an issue')
            && str_contains((string) $prompt->agent->instructions(), 'Always give them, also for something across the shot')
            && str_contains((string) $prompt->agent->instructions(), 'Is everything that matters large enough to read at a glance on a phone?')
            && str_contains((string) $prompt->agent->instructions(), 'that only happens between two keyframes or cannot be seen is an issue'));
    });

    it('draws the director\'s change and leaves what the check finds after it as a note', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'She walks into the hall, seen from behind.', 'approach' => 'redraw']]);
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => [['category' => 'place', 'change' => 'She is still at the door.', 'severity' => 'high']]]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $third = $shot->keyframes()->where('position', 3)->firstOrFail();

        (new TweakKeyframeImage($third, 'Into the hall', rewrite: true))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('She walks into the hall, seen from behind.'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
        expect($third->fresh()->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['She is still at the door.']);
    });

    it('reviews the shot as its own job once every keyframe is drawn', function () {
        Queue::fake([ReviewShot::class]);
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        expect($shot->fresh())->status->toBe(ShotStatus::KEYFRAMES_READY)->keyframe_review->toBeNull()->reviewing->toBeTrue();
        Queue::assertPushed(ReviewShot::class, fn(ReviewShot $job) => $job->shot->is($shot));

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.reviewing', true));
    });

    it('stops showing the review as busy once it is done or has failed', function () {
        Config::set('pipeline.keyframe_check', true);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY, 'reviewing' => true]);

        (new ReviewShot($shot))->handle(app(KeyframePainter::class));
        expect($shot->fresh()->reviewing)->toBeFalse();

        $shot->update(['reviewing' => true]);
        (new ReviewShot($shot))->failed(new RuntimeException('Provider down'));
        expect($shot->fresh()->reviewing)->toBeFalse();
    });

    it('rewrites the description and takes off the cast that an adjustment removes', function () {
        Config::set('pipeline.rules.enabled', true);
        Queue::fake([ClassifyCorrection::class]);
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([[
            'instruction' => 'The man is removed; the mailbox stands alone.',
            'approach' => 'edit',
            'description' => 'The red mailbox stands alone on the empty street.',
            'absent' => ['Mark, the visitor'],
        ]]);
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $first = $shot->keyframes()->firstOrFail();
        $mark = Element::factory()->for($this->project)->create();
        $mailbox = Element::factory()->for($this->project)->object()->create(['name' => 'Red mailbox']);
        $first->elements()->sync([$mark->id, $mailbox->id]);

        (new TweakKeyframeImage($first, 'remove the man', rewrite: true))->handle(app(KeyframePainter::class));

        $first->refresh()->load('elements');
        $plan = $shot->fresh()->storylineKeyframes()[0];

        expect($first->description)->toBe('The red mailbox stands alone on the empty street.')
            ->and($first->elements->pluck('name')->all())->toBe(['Red mailbox'])
            ->and($plan['description'])->toBe('The red mailbox stands alone on the empty street.')
            ->and($plan['elements'])->toBe(['Red mailbox'])
            ->and(Correction::query()->where('source', CorrectionSource::ADJUSTMENT)->value('context'))
            ->toContain('The keyframe should show: ' . plannedKeyframes()[0]['description'])
            ->not->toContain('Must show:');
        TweakInterpreter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Cast and sets in the keyframe:'));
    });

    it('keeps the description when an adjustment only changes a detail', function () {
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'The mailbox is a brighter red.', 'approach' => 'edit', 'description' => '', 'absent' => []]]);
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $first = $shot->keyframes()->firstOrFail();
        $before = $first->description;

        (new TweakKeyframeImage($first, 'brighter mailbox'))->handle(app(KeyframePainter::class));

        expect($first->fresh()->description)->toBe($before)
            ->and($shot->fresh()->storylineKeyframes()[0]['description'])->toBe(plannedKeyframes()[0]['description']);
    });

    it('keeps the description the director wrote when the adjustment comes from it', function () {
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'He drops the envelope into the slot.', 'approach' => 'edit', 'description' => 'A rewritten description.', 'absent' => []]]);
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $first = $shot->keyframes()->firstOrFail();
        $first->forceFill(['description' => 'He drops the envelope.'])->save();

        (new TweakKeyframeImage($first, 'The description changed.', rewrite: true, describedByDirector: true))->handle(app(KeyframePainter::class));

        expect($first->fresh()->description)->toBe('He drops the envelope.');
    });

    it('reviews the shot again after a keyframe is adjusted and keeps what the director resolved', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'Make the mailbox brighter.']]);
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        ShotReviewer::fake([
            ['clear' => false, 'notes' => [['keyframes' => [2, 3], 'note' => 'The envelope looks the same.']]],
            ['clear' => false, 'notes' => [['keyframes' => [3], 'note' => 'He wears a grey suit instead of navy.']]],
        ]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->refresh()->forceFill(['keyframe_review' => [...$shot->keyframe_review, 'resolved' => ['earlier' => [2]]]])->save();

        (new TweakKeyframeImage($shot->keyframes()->where('position', 2)->firstOrFail(), 'Brighter'))->handle(app(KeyframePainter::class));

        expect($shot->fresh()->keyframe_review)->toBe([
            'clear' => false,
            'notes' => [['text' => 'He wears a grey suit instead of navy.', 'keyframes' => [3]]],
            'resolved' => ['earlier' => [2]],
        ]);
    });

    it('only reviews again once every keyframe is drawn and the check is on', function (bool $check, ShotStatus $status, bool $queued) {
        Config::set('pipeline.keyframe_check', $check);
        Queue::fake();

        ReviewShot::after(plannedShot($this->project, ['status' => $status]));

        $queued ? Queue::assertPushed(ReviewShot::class) : Queue::assertNotPushed(ReviewShot::class);
    })->with([
        'ready' => [true, ShotStatus::KEYFRAMES_READY, true],
        'video ready' => [true, ShotStatus::VIDEO_READY, true],
        'still drawing' => [true, ShotStatus::KEYFRAMES_PENDING, false],
        'check off' => [false, ShotStatus::KEYFRAMES_READY, false],
    ]);

    it('keeps the keyframe when the check passes', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        expect($second->renders())->toHaveCount(1);
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
    });

    it('never checks the options for keyframe 1, which the director picks', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);

        drawFirstKeyframeOptions(plannedShot($this->project));

        KeyframeChecker::assertNeverPrompted();
    });

    it('keeps the keyframe when the check itself fails', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => throw new RuntimeException('Provider down'));
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        expect($second->renders())->toHaveCount(1)
            ->and($second->render())->not->toBeNull()
            ->and($second->generations()->where('kind', 'text')->whereNotNull('error')->count())->toBe(1);
    });

    it('creates keyframes through the images endpoint for models that only serve it', function () {
        Config::set('pipeline.models.keyframe', 'openai/gpt-image-2.5-sunburst');
        Image::fake(fn() => fakePng());
        Http::fake(['openrouter.ai/api/v1/images' => Http::response([
            'data' => [['b64_json' => fakePng(), 'media_type' => 'image/png']],
            'usage' => ['cost' => 0.074],
        ])]);

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);

        $first = $shot->keyframes()->firstOrFail();

        expect($first->renders())->toHaveCount(GenerateKeyframes::optionCount())
            ->and($first->generations()->where('kind', 'image')->first())
            ->model->toBe('openai/gpt-image-2.5-sunburst')
            ->usage->toBe(['cost' => 0.074]);

        Image::assertNothingGenerated();
        Http::assertSent(fn(HttpRequest $request) => $request['model'] === 'openai/gpt-image-2.5-sunburst'
            && $request['aspect_ratio'] === $shot->aspectRatio()->value
            && str_contains($request['prompt'], 'stands at a red mailbox'));
    });

    it('tries the images endpoint again when the gateway fails for a moment', function () {
        Sleep::fake();
        Http::fake(['openrouter.ai/api/v1/images' => Http::sequence()
            ->push('error code: 502', 502)
            ->push(['data' => [['b64_json' => base64_encode('png'), 'media_type' => 'image/png']]])]);

        $image = app(OpenRouterImageClient::class)->generate('openai/gpt-image-2.5-sunburst', 'A mailbox', [], '9:16');

        expect($image['content'])->toBe('png');
        Http::assertSentCount(2);
    });

    it('does not try the images endpoint again after a bad request', function () {
        Sleep::fake();
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['error' => 'bad prompt'], 400)]);

        expect(fn() => app(OpenRouterImageClient::class)->generate('openai/gpt-image-2.5-sunburst', 'A mailbox', [], '9:16'))
            ->toThrow(RequestException::class);
        Http::assertSentCount(1);
    });

    it('sends references to the images endpoint as unescaped PNG data URLs', function () {
        Config::set('pipeline.models.keyframe', 'openai/gpt-image-2.5-sunburst');
        Image::fake(fn() => fakePng());
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => fakePng(), 'media_type' => 'image/png']]])]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        Http::assertSent(fn(HttpRequest $request) => str_contains($request['prompt'], 'pushes a white envelope')
            && count($request['input_references']) >= 1
            && str_contains($request->body(), '"url":"data:image/png;base64,'));
    });

    it('adjusts keyframe 1 without a previous keyframe', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        (new TweakKeyframeImage($shot->keyframes()->firstOrFail(), 'Make the sign bigger'))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Make the sign bigger')
            && $prompt->contains('Edit the attached image.')
            && ! $prompt->contains('second attached image')
            && $prompt->attachments->count() === 1);
    });

    it('queues the adjustment from the inspector', function () {
        Queue::fake();
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.tweak', [$this->project, $shot, $keyframe]), ['instruction' => 'Make the sign bigger'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->rendering)->toBeTrue();

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->is($keyframe) && $job->instruction === 'Make the sign bigger');
    });

    it('rejects adjusting a keyframe without a render', function () {
        Queue::fake();

        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.tweak', [$this->project, $shot, $keyframe]), ['instruction' => 'Make the sign bigger'])
            ->assertSessionHasErrors('instruction');

        Queue::assertNothingPushed();
    });

    it('marks the keyframe when the adjustment fails', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create(['rendering' => true]);

        (new TweakKeyframeImage($keyframe, 'Anything'))->failed(new RuntimeException('Provider down'));

        expect($keyframe->fresh())->rendering->toBeFalse()->render_error->not->toBeNull();
    });
});

describe('render', function () {
    it('picks an earlier version as the chosen render', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $first = $keyframe->render()->id;
        (new TweakKeyframeImage($keyframe, 'Brighter'))->handle(app(KeyframePainter::class));
        $shot->update(['status' => ShotStatus::KEYFRAMES_READY]);
        Config::set('pipeline.keyframe_check', true);
        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $keyframe]), ['render' => $first])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->render()->id)->toBe($first);
        Queue::assertPushed(ReviewShot::class, fn(ReviewShot $job) => $job->shot->is($shot));

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->has('keyframes.1.renders', 2)
                ->where('keyframes.1.renders.0.chosen', true)
                ->where('keyframes.1.renders.1.chosen', false)
                ->where('keyframes.1.renders.1.imageUrl', fn(string $url) => str_contains($url, '?render=')));
    });

    it('rejects a render of another keyframe', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        [$first, $second] = $shot->keyframes()->get();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $first]), ['render' => $second->render()->id])
            ->assertSessionHasErrors('render');
    });

    it('streams a specific version', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->firstOrFail();

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$this->project, $shot, $keyframe, 'render' => $keyframe->render()->id]))
            ->assertSuccessful();

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$this->project, $shot, $keyframe, 'render' => 999999]))
            ->assertNotFound();
    });
});

describe('generate', function () {
    beforeEach(fn() => Queue::fake());

    it('renders the planned keyframes again', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::STORYLINE_READY, 'storyline_error' => 'Failed before']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.generate', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->storyline_error)->toBeNull();

        Queue::assertPushed(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->shot->is($shot));
    });

    it('rejects rendering before the keyframes are planned', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::DRAFT]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.generate', [$this->project, $shot]))
            ->assertSessionHasErrors('keyframes');

        Queue::assertNothingPushed();
    });

    it('forbids rendering another director\'s shot', function () {
        $shot = plannedShot(Project::factory()->create());

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.generate', [$shot->project, $shot]))
            ->assertForbidden();

        Queue::assertNothingPushed();
    });
});

describe('image', function () {
    it('streams the render of a keyframe', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        $keyframe = $shot->keyframes()->first();

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$this->project, $shot, $keyframe]))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/png');

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$this->project, $shot, $keyframe, Keyframe::THUMBNAIL]))
            ->assertSuccessful();
    });

    it('links the renders on the shot page', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->component('shots/view')
                ->has('keyframes', 3)
                ->where('keyframes.0.imageUrl', fn(string $url) => str_contains($url, '/keyframes/') && str_contains($url, '?render='))
                ->where('keyframes.0.thumbnailUrl', fn(string $url) => str_contains($url, '/thumbnail?render='))
                ->where('siblings.0.thumbnailUrl', fn(string $url) => str_contains($url, '/thumbnail?render='))
                ->where('shot.links.keyframesGenerate', route('public.shots.keyframes.generate', [$this->project, $shot])));
    });

    it('has no image links before a keyframe is rendered', function () {
        $shot = plannedShot($this->project);
        Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('keyframes.0.imageUrl', null)
                ->where('siblings.0.thumbnailUrl', null));
    });

    it('forbids viewing another director\'s render', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot(Project::factory()->create());

        renderAllKeyframes($shot);

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$shot->project, $shot, $shot->keyframes()->first()]))
            ->assertForbidden();
    });

    it('is not found when the keyframe has no render', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.keyframes.image', [$this->project, $shot, $keyframe]))
            ->assertNotFound();
    });
});

describe('starting the plan over', function () {
    it('throws the drawn keyframes away and keeps the plan and the conversation', function () {
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::KEYFRAMES_READY,
            'storyline' => ['keyframes' => plannedKeyframes()],
            'plan_chat' => [['role' => 'director', 'text' => 'yes'], ['role' => 'assistant', 'text' => 'Drawing them now.']],
        ]);
        Keyframe::factory()->for($shot)->count(2)->create();

        $shot->startPlanOver();
        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->keyframes()->count())->toBe(0)
            ->and($shot->plan_version)->toBe(1)
            ->and($shot->storylineKeyframes())->toHaveCount(3)
            ->and($shot->plan_chat)->toHaveCount(2);
    });

    it('stops the jobs of the old plan', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_PENDING, 'storyline' => ['keyframes' => plannedKeyframes()]]);
        $keyframe = Keyframe::factory()->for($shot)->create();
        $drawing = new GenerateRemainingKeyframes($shot);
        $tweaking = new TweakKeyframeImage($keyframe, 'closer');
        $runs = fn(object $job) => $job->middleware()[0]($job, fn() => true) === true;

        expect($runs($drawing))->toBeTrue()->and($runs($tweaking))->toBeTrue();

        $shot->startPlanOver();

        expect($runs($drawing))->toBeFalse()
            ->and($drawing->planReplaced())->toBeTrue()
            ->and($runs(new GenerateRemainingKeyframes($shot->fresh())))->toBeTrue();

        // The places of the old plan are not offered once they are done.
        GenerateKeyframes::finishPlates($shot->id, planVersion: 0);
        expect($shot->fresh()->status)->toBe(ShotStatus::STORYLINE_READY);
    });
});

describe('text and places in the checks', function () {
    it('never asks for text', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['description' => 'Through the windscreen the car has driven through the gate.']);

        expect((string) (new KeyframeChecker($keyframe, ['keyframe 1', 'the keyframe to check']))->instructions())
            ->toContain('never ask for text to make one recognisable')
            ->and((string) (new TweakInterpreter($keyframe, []))->instructions())
            ->toContain('Never ask for text');
    });
});

describe('length', function () {
    it('times the shot again from its keyframes and writes the voice-over for the new length', function () {
        Queue::fake([App\Jobs\GenerateVoiceOver::class]);
        App\Ai\Agents\ShotTimer::fake([['seconds' => 2]]);
        $shot = Shot::factory()->for($this->project)->create(['duration' => null, 'voice_over' => 'An old text.', 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 5], 'keyframes' => plannedKeyframes()]]);

        (new App\Jobs\RetimeShot($shot))->handle();

        expect($shot->fresh()->durationInSeconds())->toBe(2)
            ->and($shot->fresh()->voice_over)->toBeNull();
        Queue::assertPushed(App\Jobs\GenerateVoiceOver::class);
        App\Ai\Agents\ShotTimer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '2. Posting: A man in a navy suit pushes a white envelope')
            && str_contains((string) $prompt->agent->instructions(), 'picking up, handing over or putting down an object: 1 second'));
    });

    it('keeps a length the director set', function () {
        Queue::fake([App\Jobs\GenerateVoiceOver::class]);
        App\Ai\Agents\ShotTimer::fake([['seconds' => 2]]);
        $shot = Shot::factory()->for($this->project)->create(['duration' => 6, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 5], 'keyframes' => plannedKeyframes()]]);

        (new App\Jobs\RetimeShot($shot))->handle();

        expect($shot->fresh()->durationInSeconds())->toBe(6);
        App\Ai\Agents\ShotTimer::assertNeverPrompted();
        Queue::assertNotPushed(App\Jobs\GenerateVoiceOver::class);
    });

    it('times the shot again once a keyframe is added or described differently', function () {
        Queue::fake([App\Jobs\RetimeShot::class, App\Jobs\GenerateKeyframeImage::class, App\Jobs\TweakKeyframeImage::class, App\Jobs\FollowStoryline::class]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 4], 'keyframes' => plannedKeyframes()]]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'Old.']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['description' => 'He walks all the way across the hall.', 'redraw' => true])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(App\Jobs\RetimeShot::class);
    });

    it('renders a short shot at its own length', function () {
        $shot = Shot::factory()->for($this->project)->create(['duration' => null, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 2], 'keyframes' => plannedKeyframes()]]);

        expect($shot->durationInSeconds())->toBe(2)
            ->and(App\Jobs\GenerateVideo::duration($shot))->toBe(2);
    });
});
