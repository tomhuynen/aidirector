<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\TweakInterpreter;
use App\Ai\KeyframePainter;
use App\Enums\CorrectionSource;
use App\Enums\Disk;
use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\ClassifyCorrection;
use App\Jobs\DrawKeyframe;
use App\Jobs\GenerateKeyframeOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\ReviewShot;
use App\Jobs\TweakKeyframeImage;
use App\Models\Correction;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\OpenRouterImageClient;
use App\Support\Shots\KeyframeChain;
use App\Support\Shots\ShotIssues;
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
 * Runs the whole render: options for the start frame, the director picks the first, then the end frame.
 */
function renderAllKeyframes(Shot $shot): void
{
    drawFirstKeyframeOptions($shot);

    $first = $shot->keyframes()->firstOrFail();
    $first->forceFill(['render_id' => $first->renders()->first()->id, 'status' => KeyframeStatus::READY])->save();

    (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

    // With the queue faked the chain only records its draws; they are drawn here in order.
    foreach ($shot->keyframes()->orderBy('position')->get() as $keyframe) {
        if ($keyframe->refresh()->status !== KeyframeStatus::READY) {
            drawKeyframeNow($keyframe);
        }
    }
}

/**
 * Draws the options for the start frame. The options run as a batch, which a faked
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

/**
 * Draws a keyframe straight away, as the chain does when its turn comes.
 */
function drawKeyframeNow(Keyframe $keyframe): void
{
    $keyframe->forceFill(['status' => KeyframeStatus::DRAWING])->save();

    (new DrawKeyframe($keyframe, $keyframe->draw_version))->handle(app(KeyframePainter::class), app(KeyframeChain::class));
}

function plannedShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'chosen_storyline' => ['storyline' => 'He posts the letter.'],
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
            ->and($keyframes)->toHaveCount(2)
            ->and($keyframes->pluck('title')->all())->toBe(['At the mailbox', 'Posting'])
            ->and($keyframes->pluck('position')->all())->toBe([1, 2])
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->isBusy()))->toBeTrue()
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

    it('draws the end frame on the chosen start frame, the only image it gets', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $first = $shot->keyframes()->with('media')->firstOrFail();
        $bytes = Storage::disk(Disk::TENANT->value)->get($first->render()->getPathRelativeToRoot());

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox') && $prompt->attachments->isEmpty());
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('Edit the first attached image. It is the start frame of this shot')
            && $prompt->contains('This is the end frame, the result of the act.')
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->content() === $bytes);
    });

    it('attaches the pinned style sheet to the start frame only; the end frame takes the style from it', function () {
        Image::fake(fn() => fakePng());

        $this->project
            ->addMediaFromString(base64_decode(fakePng()))
            ->usingFileName('style-sheet.png')
            ->toMediaCollection(Project::STYLE_REFERENCES);

        $shot = plannedShot($this->project);

        renderAllKeyframes($shot);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox')
            && $prompt->contains('first attached image is the project\'s style reference sheet')
            && ! $prompt->contains('the start frame of this shot')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && ! $prompt->contains('style reference sheet')
            && $prompt->contains('Edit the first attached image. It is the start frame of this shot')
            && $prompt->attachments->count() === 1);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        drawKeyframeNow($keyframe);

        expect($keyframe->renders())->toHaveCount(2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope') && $prompt->attachments->count() === 1);
    });

    it('adds someone who walks in to the start frame and takes their look from their picture', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->where('position', 2)->firstOrFail()->elements()->attach($engineer);

        $siblings = $shot->keyframes()->with(['media', 'elements.media'])->get()->each->setRelation('shot', $shot);
        $second = app(KeyframePainter::class)->referencesFor($siblings->firstWhere('position', 2), $siblings);

        expect($second->firstShowsCast)->toBeFalse()
            ->and($second->castNames)->toBe(['Female engineer'])
            ->and($second->style)->toBeNull()
            ->and($second->images())->toHaveCount(1)
            ->and($second->images()[0]->path)->toBe($siblings->firstWhere('position', 1)->render()->getPathRelativeToRoot());
    });

    it('checks the end frame against the start frame for the place, and the people against their picture', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        $shot->keyframes()->where('position', 2)->firstOrFail()->elements()->attach($engineer);

        drawKeyframeNow($shot->keyframes()->where('position', 2)->firstOrFail());

        // She is not in the start frame, which is then only the reference for the place.
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Image 1: the start frame of the shot: the reference for the place and the camera; Female engineer is not in it yet.')
            && str_contains((string) $prompt->agent->instructions(), 'Image 2: the keyframe to check.')
            && $prompt->attachments->count() === 2);
    });

    it('uses the start frame as it is when it shows no people', function () {
        Image::fake(fn() => fakePng());
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('remove every person from it'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('It is the start frame of this shot'));
    });

    it('draws a keyframe again when its background moved compared with the image it was drawn on', function () {
        $calls = 0;
        // Three options for the start frame, then the end frame moves once before it lines up.
        Image::fake(function () use (&$calls) {
            $calls++;

            return stripedPng($calls === 4 ? 6 : 0);
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        expect($calls)->toBe(5)
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

        expect($calls)->toBe(4)
            ->and($second->render()->getCustomProperty(Keyframe::STILLNESS))->toBeLessThan(0.85)
            ->and($second->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeNull();
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

        // Three options for the start frame, three attempts for the end frame.
        expect($calls)->toBe(3 + 3)
            ->and($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeTrue();

        $issues = app(ShotIssues::class);
        $group = collect($issues->groups($shot->fresh(), $keyframes))->firstWhere('key', 'keyframe-2');

        expect($group['issues'])->toContain('The background moved compared with the place, also after drawing it again.');

        Queue::fake([DrawKeyframe::class, TweakKeyframeImage::class]);
        $issues->fix($shot->fresh(), 'keyframe-2');

        Queue::assertPushed(DrawKeyframe::class);
        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect($second->fresh()->render()->getCustomProperty(Keyframe::BACKGROUND_MOVED))->toBeNull();
    });

    it('replaces the keyframes of an earlier render', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        $stale = Keyframe::factory()->for($shot)->create(['title' => 'Old keyframe']);

        renderAllKeyframes($shot);

        expect(Keyframe::query()->whereKey($stale->id)->exists())->toBeFalse()
            ->and($shot->keyframes()->count())->toBe(2);
    });

    it('keeps the plan and reports an error when no option for the start frame can be drawn', function () {
        Image::fake(fn() => throw new RuntimeException('Provider down'));

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle();

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->storyline_error)->not->toBeNull()
            ->and($shot->storylineKeyframes())->toEqual(plannedKeyframes())
            ->and($shot->keyframes()->first()->isBusy())->toBeFalse()
            ->and($shot->keyframes()->first()->render_error)->toBe('No option could be drawn.')
            ->and($shot->keyframes()->first()->generations()->where('error', 'Provider down')->count())->toBe(GenerateKeyframes::optionCount());
    });
});

describe('start frame', function () {
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
        $first = Keyframe::factory()->for($shot)->create(['position' => 1, 'status' => KeyframeStatus::DRAWING]);

        GenerateKeyframes::finishOptions($shot->id);

        expect($shot->fresh())->status->toBe(ShotStatus::STORYLINE_READY)->storyline_error->not->toBeNull()
            ->and($first->fresh())->status->toBe(KeyframeStatus::FAILED)->render_error->not->toBeNull();

        (new GenerateKeyframeOption($first, 0))->handle(app(KeyframePainter::class));
        GenerateKeyframes::finishOptions($shot->id, failed: 2);

        expect($shot->fresh())->status->toBe(ShotStatus::FIRST_KEYFRAME_READY)->storyline_error->toContain('Not every option')
            ->and($first->fresh())->render_id->toBeNull()->render_error->toBeNull();

        GenerateKeyframes::finishOptions($shot->id);

        expect($shot->fresh()->storyline_error)->toBeNull();
    });

    it('draws a single start frame and waits for the director to confirm it before drawing the end frame', function () {
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

    it('draws options for the start frame only and waits for a choice', function () {
        Image::fake(fn() => numberedPng());

        $shot = plannedShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);

        (new GenerateKeyframes($shot))->handle();

        $shot->refresh();
        [$first, $second] = $shot->keyframes()->get();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($shot->keyframes()->count())->toBe(2)
            ->and($first->renders())->toHaveCount(3)
            ->and($first->render_id)->toBeNull()
            ->and($first->isBusy())->toBeFalse()
            ->and($second->renders())->toHaveCount(0)
            ->and($second->isBusy())->toBeFalse();

        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Stage it as described.'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Choose a different calm part of the same place'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Variation for this option: Choose different lighting'));
    });

    it('adjusts one option for the start frame and adds the result as a new option', function () {
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
            ->and($first->isBusy())->toBeFalse()
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

        expect($first->fresh()->isBusy())->toBeFalse()->and($first->fresh())->render_id->toBeNull()
            ->and($first->fresh()->renders())->toHaveCount(GenerateKeyframes::optionCount() + 1);
    });

    it('does not choose an option while an adjusted one is being drawn', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();
        $first->forceFill(['status' => KeyframeStatus::DRAWING])->save();
        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.choose', [$this->project, $shot]), ['render' => $first->renders()->first()->id])
            ->assertSessionHasErrors('render');

        expect($first->fresh()->render_id)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('only adjusts options while the start frame is waiting for a choice', function () {
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

    it('draws the end frame from the chosen option', function () {
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
            ->and($shot->keyframes()->where('position', 2)->first()->isBusy())->toBeTrue();

        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->shot->is($shot));

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

        // With the queue faked the chain only records its draws; they are drawn here in order.
        foreach ($shot->keyframes()->where('position', '>', 1)->orderBy('position')->get() as $keyframe) {
            drawKeyframeNow($keyframe);
        }

        $chosenBytes = Storage::disk(Disk::TENANT->value)->get($chosen->getPathRelativeToRoot());

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($shot->keyframes()->get()->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->isBusy()))->toBeTrue();

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

    it('only accepts a choice while the start frame is waiting for one', function () {
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

    it('goes back to choosing when the end frame fails', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_PENDING]);
        Keyframe::factory()->for($shot)->create(['position' => 2, 'status' => KeyframeStatus::DRAWING]);

        (new GenerateRemainingKeyframes($shot))->failed(new RuntimeException('Provider down'));

        expect($shot->fresh())->status->toBe(ShotStatus::FIRST_KEYFRAME_READY)->storyline_error->not->toBeNull()
            ->and($shot->keyframes()->first()->isBusy())->toBeFalse();
    });
});

describe('update', function () {
    beforeEach(fn() => Queue::fake());

    it('stages the keyframe anew, builds its description and renders it again', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting', 'render_error' => 'Old error']);
        $description = 'He drops the envelope in the slot. A red mailbox on a quiet street. The Man stands on the right, is side-on facing left, looks at the mailbox, holds the envelope in the right hand and smiles.';

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), [
                'summary' => 'He drops the envelope in the slot',
                'place' => 'A red mailbox on a quiet street',
                'cast' => [['name' => 'Man', 'stands' => 'right', 'faces' => 'left', 'looks_at' => 'the mailbox', 'holds' => 'the envelope', 'hand' => 'right', 'doing' => 'smiles']],
            ])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $keyframe->refresh();

        expect($keyframe->description)->toBe($description)
            ->and($keyframe->staged()->cast[0]['looks_at'])->toBe('the mailbox')
            ->and($keyframe->isBusy())->toBeTrue()
            ->and($keyframe->render_error)->toBeNull()
            ->and($shot->fresh()->storylineKeyframes()[1])->toMatchArray(['title' => 'Posting', 'description' => $description, 'staging' => $keyframe->staging]);

        Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->is($keyframe));
    });

    it('adjusts the current image to a changed description and keeps the wording', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting', 'description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => 'He drops the envelope in the slot.', 'cast' => [], 'following' => true])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->description)->toBe('He drops the envelope in the slot.');

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->is($keyframe)
            && $job->rewrite
            && $job->describedByDirector
            && $job->following
            && str_contains($job->instruction, '"He holds the envelope." to "He drops the envelope in the slot."'));
        Queue::assertNotPushed(DrawKeyframe::class);
    });

    it('draws the keyframe again from its description when asked, also unchanged', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => 'He holds the envelope.', 'cast' => [], 'redraw' => true])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->is($keyframe));
        Queue::assertNotPushed(TweakKeyframeImage::class);
    });

    it('needs a changed staging to adjust the image', function () {
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['description' => 'He holds the envelope.']);
        $render = $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => 'He holds the envelope. ', 'cast' => []])
            ->assertSessionHasErrors('summary');

        Queue::assertNotPushed(TweakKeyframeImage::class);
        Queue::assertNotPushed(DrawKeyframe::class);
        expect($keyframe->fresh()->isBusy())->toBeFalse();
    });

    it('drops the copy mark of the old wording', function () {
        $plans = plannedKeyframes();
        $plans[1] = [...$plans[1], 'copied' => true];
        $shot = plannedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['keyframes' => $plans]]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Posting']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => 'The street is empty; he is not there yet.', 'cast' => []]);

        expect($shot->fresh()->storylineKeyframes()[1])->not->toHaveKey('copied')
            ->and($shot->fresh()->plannedKeyframeIsCopy(2))->toBeFalse();
    });

    it('requires what happens and the cast', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => ''])
            ->assertSessionHasErrors(['summary', 'cast']);

        Queue::assertNothingPushed();
    });

    it('forbids changing another director\'s keyframe', function () {
        $shot = plannedShot(Project::factory()->create());
        $keyframe = Keyframe::factory()->for($shot)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$shot->project, $shot, $keyframe]), ['summary' => 'Changed', 'cast' => []])
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
        $keyframe->forceFill(['status' => KeyframeStatus::DRAWING])->save();

        drawKeyframeNow($keyframe);

        $keyframe->refresh();

        expect($keyframe->isBusy())->toBeFalse()
            ->and($keyframe->render()->id)->not->toBe($previous)
            ->and($keyframe->renders())->toHaveCount(2);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('smiles at the mailbox')
            && $prompt->contains('the start frame of this shot')
            && $prompt->attachments->count() === 1);
    });

    it('marks the keyframe when its render fails', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create(['status' => KeyframeStatus::DRAWING]);

        (new DrawKeyframe($keyframe, $keyframe->draw_version))->failed(new RuntimeException('Provider down'));

        expect($keyframe->fresh()->isBusy())->toBeFalse()->and($keyframe->fresh())->render_error->not->toBeNull();
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

        TweakInterpreter::fake([['instruction' => 'The helmet on his head is plain blue without any lettering.', 'approach' => 'redraw', 'restage' => true, 'staging' => ['summary' => 'He wears a plain blue helmet.', 'place' => '', 'cast' => []], 'absent' => []]]);

        (new TweakKeyframeImage($keyframe, 'Make his helmet plain blue'))->handle(app(KeyframePainter::class));

        // The text model only brings the staging up to date; the image model gets the request as typed, as an edit.
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: Make his helmet plain blue'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('plain blue without any lettering'));
        expect($keyframe->fresh()->description)->toBe('He wears a plain blue helmet.');

        $sent = $keyframe->fresh()->render()->getCustomProperty(Keyframe::SENT);

        expect($sent['prompt'])->toContain('Change only this: Make his helmet plain blue')
            ->and($sent['images'])->toBe(['This version, the image that is edited', 'The start frame']);
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
            ->and($keyframe->isBusy())->toBeFalse()
            ->and($keyframe->prompt)->toContain('pushes a white envelope')
            ->and($keyframe->generations()->latest('id')->first()->prompt)->toContain('Change only this: Remove the lighter from his hand')
            ->and($keyframe->generations()->latest('id')->first()->model)->toBe('openai/gpt-5.4-image-2');

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Remove the lighter from his hand')
            && $prompt->model === 'openai/gpt-5.4-image-2'
            && $prompt->contains('Edit the first attached image.')
            && $prompt->contains('The second attached image is the start frame of the same shot')
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
            $stages[] = Keyframe::query()->where('position', 2)->value('status');

            return ['inventory' => [], 'issues' => [
                ['category' => 'place', 'change' => 'The DAMEN logo is missing from the hall.', 'severity' => 'high'],
                ['category' => 'place', 'change' => 'A window is a little narrower.', 'severity' => 'low'],
            ]];
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->with('media')->where('position', 2)->firstOrFail();

        expect($stages[0])->toBe(KeyframeStatus::CHECKING)
            ->and($second->isBusy())->toBeFalse()
            ->and($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['The DAMEN logo is missing from the hall.']);
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'What the keyframe to check should show:'));

        $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        expect(app(ShotIssues::class)->openNotes($shot->fresh(), $shot->keyframes()->with('media')->get()))
            ->toContain('Keyframe 2: The check found: The DAMEN logo is missing from the hall.');
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
            && str_contains((string) $prompt->agent->instructions(), 'Image 1: the start frame of the shot: the reference for the place and the camera, for the state of objects and for how the people look.'));
    });

    it('reviews all keyframes together against the storyline and keeps the notes', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        // Keyframe 7 does not exist, so it is dropped.
        ShotReviewer::fake(fn() => ['clear' => false, 'notes' => [['keyframes' => [2, 7], 'note' => 'The envelope looks the same, so posting cannot be seen.']]]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        expect($shot->fresh()->keyframe_review)->toBe(['clear' => false, 'notes' => [['text' => 'The envelope looks the same, so posting cannot be seen.', 'keyframes' => [2]]]]);
        ShotReviewer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Storyline: He posts the letter.') && $prompt->attachments->count() === 2);
        ShotReviewer::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'recognisable as the same person throughout')
            && str_contains((string) $prompt->agent->instructions(), 'people with different names are different people on purpose'));
        ShotReviewer::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Do the start frame and the end frame differ at a glance?')
            && str_contains((string) $prompt->agent->instructions(), 'Someone who enters a place moves into it')
            && str_contains((string) $prompt->agent->instructions(), 'Words, logos or numbers that appear or disappear between keyframes')
            && str_contains((string) $prompt->agent->instructions(), 'a floor line that runs differently is an issue')
            && str_contains((string) $prompt->agent->instructions(), 'Always give them, also for something across the shot')
            && str_contains((string) $prompt->agent->instructions(), 'Is everything that matters large enough to read at a glance on a phone?')
            && str_contains((string) $prompt->agent->instructions(), 'cannot be played in one shot: that is an issue'));
    });

    it('draws the director\'s change and leaves what the check finds after it as a note', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'She walks into the hall, seen from behind.', 'approach' => 'redraw']]);
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => [['category' => 'place', 'change' => 'She is still at the door.', 'severity' => 'high']]]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $end = $shot->keyframes()->where('position', 2)->firstOrFail();

        (new TweakKeyframeImage($end, 'Into the hall', rewrite: true))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('She walks into the hall, seen from behind.'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
        expect($end->fresh()->render()->getCustomProperty(Keyframe::CHECK_ISSUES))->toBe(['She is still at the door.']);
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

    it('stages the keyframe anew and takes off the cast that an adjustment removes', function () {
        Config::set('pipeline.rules.enabled', true);
        Queue::fake([ClassifyCorrection::class]);
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([[
            'instruction' => 'The man is removed; the mailbox stands alone.',
            'approach' => 'edit',
            'restage' => true,
            'staging' => ['summary' => 'The mailbox stands alone', 'place' => 'The red mailbox stands alone on the empty street.', 'cast' => []],
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

        expect($first->description)->toBe('The mailbox stands alone. The red mailbox stands alone on the empty street.')
            ->and($first->staged()->summary)->toBe('The mailbox stands alone')
            ->and($first->elements->pluck('name')->all())->toBe(['Red mailbox'])
            ->and($plan['description'])->toBe('The mailbox stands alone. The red mailbox stands alone on the empty street.')
            ->and($plan['staging'])->toBe($first->staging)
            ->and($plan['elements'])->toBe(['Red mailbox'])
            ->and(Correction::query()->where('source', CorrectionSource::ADJUSTMENT)->value('context'))
            ->toContain('The keyframe should show: ' . plannedKeyframes()[0]['description'])
            ->not->toContain('Must show:');
        TweakInterpreter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Cast and sets in the keyframe:')
            && str_contains((string) $prompt->agent->instructions(), 'How the keyframe is staged now:'));
    });

    it('keeps the staging when an adjustment only changes a detail', function () {
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'The mailbox is a brighter red.', 'approach' => 'edit', 'restage' => false, 'staging' => ['summary' => 'Ignored.', 'place' => '', 'cast' => []], 'absent' => []]]);
        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $first = $shot->keyframes()->firstOrFail();
        $before = $first->description;

        (new TweakKeyframeImage($first, 'brighter mailbox'))->handle(app(KeyframePainter::class));

        expect($first->fresh()->description)->toBe($before)
            ->and($shot->fresh()->storylineKeyframes()[0]['description'])->toBe(plannedKeyframes()[0]['description']);
    });

    it('keeps the staging the director made when the adjustment comes from it', function () {
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'He drops the envelope into the slot.', 'approach' => 'edit', 'restage' => true, 'staging' => ['summary' => 'A rewritten line.', 'place' => '', 'cast' => []], 'absent' => []]]);
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
            ['clear' => false, 'notes' => [['keyframes' => [2], 'note' => 'The envelope looks the same.']]],
            ['clear' => false, 'notes' => [['keyframes' => [2], 'note' => 'He wears a grey suit instead of navy.']]],
        ]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $shot->refresh()->forceFill(['keyframe_review' => [...$shot->keyframe_review, 'resolved' => ['earlier' => [2]]]])->save();

        (new TweakKeyframeImage($shot->keyframes()->where('position', 2)->firstOrFail(), 'Brighter'))->handle(app(KeyframePainter::class));

        expect($shot->fresh()->keyframe_review)->toBe([
            'clear' => false,
            'notes' => [['text' => 'He wears a grey suit instead of navy.', 'keyframes' => [2]]],
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

    it('never checks the options for the start frame, which the director picks', function () {
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

    it('adjusts the start frame with that image alone', function () {
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

        expect($keyframe->fresh()->isBusy())->toBeTrue();

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
        $keyframe = Keyframe::factory()->for($shot)->create(['status' => KeyframeStatus::DRAWING]);

        (new TweakKeyframeImage($keyframe, 'Anything'))->failed(new RuntimeException('Provider down'));

        expect($keyframe->fresh()->isBusy())->toBeFalse()->and($keyframe->fresh())->render_error->not->toBeNull();
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
        // The earlier version was drawn on the start frame: where everyone stands in it comes along.
        $layout = ['cast' => [['name' => 'Man', 'box' => [100, 100, 900, 400], 'facing' => 'right', 'pose' => 'posts the letter', 'changed' => true]], 'changes' => []];
        $keyframe->renders()->firstWhere('id', $first)->setCustomProperty(Keyframe::LAYOUT, $layout)->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $keyframe]), ['render' => $first])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->render()->id)->toBe($first)
            ->and($keyframe->fresh()->layout)->toEqual($layout);
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
                ->has('keyframes', 2)
                ->where('keyframes.0.imageUrl', fn(string $url) => str_contains($url, '/keyframes/') && str_contains($url, '?render='))
                ->where('keyframes.0.thumbnailUrl', fn(string $url) => str_contains($url, '/thumbnail?render='))
                ->where('siblings.0.thumbnailUrl', fn(string $url) => str_contains($url, '/thumbnail?render='))
                ->where('shot.links.retry', route('public.shots.retry', [$this->project, $shot])));
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
            ->and($shot->storylineKeyframes())->toHaveCount(2)
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
    });
});

describe('text in the checks', function () {
    it('never asks for text', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create(['description' => 'Through the windscreen the car has driven through the gate.']);

        expect((string) (new KeyframeChecker($keyframe, ['keyframe 1', 'the keyframe to check']))->instructions())
            ->toContain('never ask for text to make one recognisable')
            ->toContain('hidden behind a person or a thing standing in front of it, wholly or in part: it is covered, not gone')
            ->and((string) (new TweakInterpreter($keyframe, []))->instructions())
            ->toContain('Never ask for text');
    });
});

describe('length', function () {
    it('times the shot again from its keyframes and writes the voice-over for the new length', function () {
        Config::set('pipeline.voice_over.automatic', true);
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

    it('keeps how long each keyframe takes, as the timer gives it', function () {
        Queue::fake([App\Jobs\GenerateVoiceOver::class]);
        $count = count(plannedKeyframes());
        App\Ai\Agents\ShotTimer::fake([['seconds' => 4, 'keyframes' => [...array_fill(0, $count - 1, 1), 2]]]);
        $shot = Shot::factory()->for($this->project)->create(['duration' => null, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 5], 'keyframes' => plannedKeyframes()]]);

        (new App\Jobs\RetimeShot($shot))->handle();

        $seconds = array_column($shot->fresh()->storylineKeyframes(), 'seconds');

        expect($seconds)->toEqual([...array_fill(0, $count - 1, 1), 2])
            ->and($shot->fresh()->durationInSeconds())->toBe(4);
        App\Ai\Agents\ShotTimer::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'how long it takes from it to the next one'));
    });

    it('leaves the voice-over to the director unless voice-overs are made automatically, except for a presenter', function () {
        Queue::fake([App\Jobs\GenerateVoiceOver::class]);
        App\Ai\Agents\ShotTimer::fake([['seconds' => 2], ['seconds' => 2]]);
        $shot = Shot::factory()->for($this->project)->create(['duration' => null, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 5], 'keyframes' => plannedKeyframes()]]);

        (new App\Jobs\RetimeShot($shot))->handle();

        Queue::assertNotPushed(App\Jobs\GenerateVoiceOver::class);

        $presenter = Shot::factory()->for($this->project)->create(['kind' => 'presenter', 'duration' => null, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 5], 'keyframes' => plannedKeyframes()]]);

        (new App\Jobs\RetimeShot($presenter))->handle();

        Queue::assertPushed(App\Jobs\GenerateVoiceOver::class, fn($job) => $job->shot->is($presenter));
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

    it('times the shot again once a keyframe is described differently', function () {
        Queue::fake([App\Jobs\RetimeShot::class, App\Jobs\DrawKeyframe::class, App\Jobs\TweakKeyframeImage::class, App\Jobs\FollowStoryline::class]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 4], 'keyframes' => plannedKeyframes()]]);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1, 'description' => 'Old.']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $keyframe]), ['summary' => 'He walks all the way across the hall.', 'cast' => [], 'redraw' => true])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(App\Jobs\RetimeShot::class);
    });

    it('renders a short shot at its own length', function () {
        $shot = Shot::factory()->for($this->project)->create(['duration' => null, 'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 2], 'keyframes' => plannedKeyframes()]]);

        expect($shot->durationInSeconds())->toBe(2)
            ->and(App\Jobs\GenerateVideo::duration($shot))->toBe(2);
    });
});
