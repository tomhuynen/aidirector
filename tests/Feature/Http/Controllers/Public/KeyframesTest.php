<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\TweakInterpreter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\GenerateKeyframeOption;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Bus\PendingBatch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        ['title' => 'At the mailbox', 'description' => 'The man stands at the mailbox holding the envelope.', 'prompt' => 'A man in a navy suit stands at a red mailbox holding a white envelope.'],
        ['title' => 'Posting', 'description' => 'The envelope slides into the slot.', 'prompt' => 'A man in a navy suit pushes a white envelope into the slot of a red mailbox.'],
        ['title' => 'Thumbs up', 'description' => 'The man gives a thumbs up.', 'prompt' => 'A man in a navy suit gives a thumbs up next to a red mailbox.'],
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
            && $prompt->contains('first attached image is keyframe 1 of this shot')
            && ! $prompt->contains('directly before this one')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('gives a thumbs up')
            && $prompt->contains('first attached image is keyframe 1 of this shot')
            && $prompt->contains('second attached image is the keyframe directly before this one')
            && $prompt->attachments->count() === 2
            && $prompt->attachments->first()->content() === $bytes($first)
            && $prompt->attachments->last()->content() === $bytes($second));
    });

    it('attaches the pinned style sheet ahead of the first keyframe', function () {
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
            && $prompt->contains('first attached image is the project\'s style reference sheet')
            && $prompt->contains('second attached image is keyframe 1 of this shot')
            && $prompt->attachments->count() === 2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('gives a thumbs up')
            && $prompt->contains('third attached image is the keyframe directly before this one')
            && $prompt->attachments->count() === 3);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        (new GenerateKeyframeImage($keyframe))->handle(app(KeyframePainter::class));

        expect($keyframe->renders())->toHaveCount(2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope') && $prompt->attachments->count() === 2);
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
        TweakInterpreter::fake([['instruction' => 'The red line runs across the quay right in front of her feet.']]);

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();
        $option = $first->renders()->get(1);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.adjust', [$this->project, $shot]), ['render' => $option->id, 'instruction' => 'Put the line across her path'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $first->refresh();
        $added = $first->renders()->last();

        expect($first->renders())->toHaveCount(GenerateKeyframes::optionCount() + 1)
            ->and($first->render_id)->toBeNull()
            ->and($first->rendering)->toBeFalse()
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_READY)
            ->and($added->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('Put the line across her path');

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The red line runs across the quay right in front of her feet.')
            && $prompt->attachments->first()->path === $option->getPathRelativeToRoot());
    });

    it('draws an option again instead of editing it when the change moves people through the scene', function () {
        Config::set('pipeline.models.keyframe', 'create/model');
        Config::set('pipeline.models.image_edit', 'edit/model');
        Image::fake(fn() => fakePng());
        TweakInterpreter::fake([['instruction' => 'She stands right next to the crane, the container almost above her.', 'approach' => 'redraw']]);

        $shot = plannedShot($this->project);
        drawFirstKeyframeOptions($shot);
        $first = $shot->keyframes()->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.first.adjust', [$this->project, $shot]), ['render' => $first->renders()->first()->id, 'instruction' => 'Closer to the crane'])
            ->assertSessionHasNoErrors();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'create/model'
            && $prompt->contains('The director asked for this change, and it matters most: She stands right next to the crane')
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
            ->and($shot->fresh()->storylineKeyframes()[3])->toEqual(['title' => 'Walks away', 'description' => 'The man walks away from the mailbox, smiling.', 'prompt' => 'The man walks away from the mailbox, smiling.']);

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

        config(['pipeline.keyframes.max' => 3]);
        $full = plannedShot($this->project);
        renderAllKeyframes($full);
        $full->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.store', [$this->project, $full]), ['title' => 'Extra', 'description' => 'More.'])
            ->assertSessionHasErrors('description');

        Queue::assertNotPushed(GenerateKeyframeImage::class);
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
            ->and($shot->fresh()->storylineKeyframes()[1])->toEqual(['title' => 'Posting', 'description' => 'He drops the envelope in the slot and smiles.', 'prompt' => 'He drops the envelope in the slot and smiles.']);

        Queue::assertPushed(GenerateKeyframeImage::class, fn(GenerateKeyframeImage $job) => $job->keyframe->is($keyframe));
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
        $plan[1]['prompt'] = 'A man in a navy suit smiles at the mailbox.';
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

        (new TweakKeyframeImage($keyframe, 'He should look backwards at the sign'))->handle(app(KeyframePainter::class));

        $render = $keyframe->fresh()->render();

        expect($render->getCustomProperty(Keyframe::TWEAK_REQUEST))->toBe('He should look backwards at the sign')
            ->and($render->getCustomProperty(Keyframe::TWEAK_INSTRUCTION))->toBe('The sign is behind him on the left of the frame. He looks back over his right shoulder at it.');

        TweakInterpreter::assertPrompted(fn(AgentPrompt $prompt) => str_contains($prompt->prompt, 'He should look backwards at the sign')
            && $prompt->attachments->count() === 2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Change only this: The sign is behind him on the left of the frame.')
            && ! $prompt->contains('He should look backwards'));

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('keyframes.1.renders.1.request', 'He should look backwards at the sign')
                ->where('keyframes.1.renders.1.instruction', 'The sign is behind him on the left of the frame. He looks back over his right shoulder at it.')
                ->where('keyframes.1.renders.0.instruction', null));
    });

    it('sends the request as typed when the rewrite fails', function () {
        TweakInterpreter::fake(fn() => throw new RuntimeException('text model down'));
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);
        $keyframe = $shot->keyframes()->firstOrFail();

        (new TweakKeyframeImage($keyframe, 'Make the sign bigger'))->handle(app(KeyframePainter::class));

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
        Config::set('pipeline.models.image_edit', 'edit/model');
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        (new TweakKeyframeImage($shot->keyframes()->firstOrFail(), 'Look back at the sign'))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'edit/model' && $prompt->contains('Look back at the sign'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'edit/model' && ! $prompt->contains('Look back at the sign'));
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'create/model');
    });

    it('redraws a keyframe once when the automatic check finds a clear mistake', function () {
        Config::set('pipeline.keyframe_check', true);
        $notes = [];
        Image::fake(function (ImagePrompt $prompt) use (&$notes) {
            if ($prompt->contains('Correct these mistakes')) {
                $notes[] = Keyframe::query()->where('rendering', true)->value('render_note');
            }

            return fakePng();
        });
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        $stages = [];
        KeyframeChecker::fake(function () use (&$stages) {
            $stages[] = Keyframe::query()->where('position', 2)->value('render_stage');

            return ['passes' => false, 'problems' => ['The DAMEN logo is missing from the hall.'], 'fix' => 'Put the DAMEN logo back on the hall facade as in keyframe 1.'];
        });

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();
        $chosen = $second->render();

        expect($stages[0])->toBe(Keyframe::STAGE_CHECKING)
            ->and($notes[0])->toBe('The DAMEN logo is missing from the hall.')
            ->and($second->render_note)->toBeNull()
            ->and($second->rendering)->toBeFalse()
            ->and($second->render_stage)->toBeNull()
            ->and($second->renders())->toHaveCount(2)
            ->and($chosen->getCustomProperty(Keyframe::CHECK_PROBLEMS))->toBe(['The DAMEN logo is missing from the hall.'])
            ->and($second->generations()->where('kind', 'text')->count())->toBe(1);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes from an earlier attempt: Put the DAMEN logo back'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'What the keyframe should show:'));
    });

    it('puts what the keyframe must show first and warns when it stays missing after a redraw', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['must_show_visible' => false, 'passes' => false, 'problems' => ['She stands far from the container.'], 'fix' => 'Bring the container close to her.']);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $plans = plannedKeyframes();
        $plans[1]['must_show'] = 'The envelope is halfway into the slot, his hand still on it.';
        $shot = plannedShot($this->project, ['storyline' => ['keyframes' => $plans]]);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        expect($second->render()->getCustomProperty(Keyframe::CHECK_WARNING))->toBe('The envelope is halfway into the slot, his hand still on it.');

        Image::assertGenerated(fn(ImagePrompt $prompt) => str_starts_with(explode("\n\n", (string) $prompt->prompt)[1] ?? '', 'Most important, this must be clearly visible: The envelope is halfway into the slot'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Must show: The envelope is halfway into the slot'));
    });

    it('reviews all keyframes together against the takeaway and keeps the notes', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['must_show_visible' => true, 'passes' => true, 'problems' => [], 'fix' => '']);
        ShotReviewer::fake(fn() => ['clear' => false, 'notes' => ['In keyframes 2 and 3 the envelope looks the same, so posting cannot be seen.']]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        expect($shot->fresh()->keyframe_review)->toBe(['clear' => false, 'notes' => ['In keyframes 2 and 3 the envelope looks the same, so posting cannot be seen.']]);
        ShotReviewer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'The shot teaches: Sending the letter is easy and final') && $prompt->attachments->count() === 3);
    });

    it('keeps the keyframe when the check passes', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['passes' => true, 'problems' => [], 'fix' => '']);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);

        $shot = plannedShot($this->project);
        renderAllKeyframes($shot);

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        expect($second->renders())->toHaveCount(1)
            ->and($second->render()->getCustomProperty(Keyframe::CHECK_PROBLEMS))->toBeNull();
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Correct these mistakes'));
    });

    it('never checks the options for keyframe 1, which the director picks', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => fakePng());
        KeyframeChecker::fake(fn() => ['passes' => true, 'problems' => [], 'fix' => '']);

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

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $keyframe]), ['render' => $first])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->render()->id)->toBe($first);

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
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_READY]);

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
