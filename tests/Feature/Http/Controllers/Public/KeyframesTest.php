<?php

declare(strict_types=1);

use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\GenerateKeyframes;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
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

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

        $shot->refresh();
        $keyframes = $shot->keyframes()->get();

        expect($shot->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($shot->storyline_error)->toBeNull()
            ->and($keyframes)->toHaveCount(3)
            ->and($keyframes->pluck('title')->all())->toBe(['At the mailbox', 'Posting', 'Thumbs up'])
            ->and($keyframes->pluck('position')->all())->toBe([1, 2, 3])
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->rendering))->toBeTrue()
            ->and($keyframes->first()->prompt)->toContain('A man in a navy suit stands at a red mailbox')
            ->and($keyframes->first()->generations()->where('kind', 'image')->count())->toBe(1);

        Storage::disk(Disk::TENANT->value)->assertExists($keyframes->first()->render()->getPathRelativeToRoot());
    });

    it('sends the project style and the shot aspect ratio to the image model', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->size === $shot->aspectRatio()->value
            && $prompt->contains('Visual style:')
            && $prompt->contains('A man in a navy suit stands at a red mailbox'));
    });

    it('uses the first render as the reference for the following keyframes', function () {
        Image::fake(fn() => fakePng());

        (new GenerateKeyframes(plannedShot($this->project)))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox') && $prompt->attachments->isEmpty());
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope') && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('gives a thumbs up') && $prompt->attachments->count() === 1);
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox') && $prompt->contains('earlier keyframe'));
    });

    it('attaches the pinned style sheet ahead of the first keyframe', function () {
        Image::fake(fn() => fakePng());

        $this->project
            ->addMediaFromString(base64_decode(fakePng()))
            ->usingFileName('style-sheet.png')
            ->toMediaCollection(Project::STYLE_REFERENCES);

        $shot = plannedShot($this->project);

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('stands at a red mailbox')
            && $prompt->contains('first attached image is the project\'s style reference sheet')
            && ! $prompt->contains('earlier keyframe')
            && $prompt->attachments->count() === 1);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope')
            && $prompt->contains('first attached image is the project\'s style reference sheet')
            && $prompt->contains('second attached image is an earlier keyframe')
            && $prompt->attachments->count() === 2);

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        (new GenerateKeyframeImage($keyframe))->handle(app(KeyframePainter::class));

        expect($keyframe->renders())->toHaveCount(2);
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('pushes a white envelope') && $prompt->attachments->count() === 2);
    });

    it('replaces the keyframes of an earlier render', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        $stale = Keyframe::factory()->for($shot)->create(['title' => 'Old keyframe']);

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

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
            ->and($keyframe->prompt)->toContain('He drops the envelope in the slot and smiles.')
            ->and($keyframe->prompt)->toContain('an earlier keyframe of the same shot')
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

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $previous = $keyframe->render()->id;
        $keyframe->forceFill(['prompt' => 'A man in a navy suit smiles at the mailbox.', 'rendering' => true])->save();

        (new GenerateKeyframeImage($keyframe))->handle(app(KeyframePainter::class));

        $keyframe->refresh();

        expect($keyframe->rendering)->toBeFalse()
            ->and($keyframe->render()->id)->not->toBe($previous)
            ->and($keyframe->renders())->toHaveCount(2);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('smiles at the mailbox') && $prompt->attachments->count() === 1);
    });

    it('marks the keyframe when its render fails', function () {
        $shot = plannedShot($this->project);
        $keyframe = Keyframe::factory()->for($shot)->create(['rendering' => true]);

        (new GenerateKeyframeImage($keyframe))->failed(new RuntimeException('Provider down'));

        expect($keyframe->fresh())->rendering->toBeFalse()->render_error->not->toBeNull();
    });
});

describe('tweak', function () {
    it('adjusts the current render and keeps the earlier version', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $previous = $keyframe->render()->id;

        (new TweakKeyframeImage($keyframe, 'Remove the lighter from his hand'))->handle(app(KeyframePainter::class));

        $keyframe->refresh();

        expect($keyframe->renders())->toHaveCount(2)
            ->and($keyframe->render()->id)->not->toBe($previous)
            ->and($keyframe->render_id)->toBe($keyframe->render()->id)
            ->and($keyframe->rendering)->toBeFalse()
            ->and($keyframe->prompt)->toContain('pushes a white envelope')
            ->and($keyframe->generations()->latest('id')->first()->prompt)->toContain('Change only this: Remove the lighter from his hand');

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Remove the lighter from his hand')
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->content() === base64_decode(fakePng()));
    });

    it('queues the adjustment from the inspector', function () {
        Queue::fake();
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));
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
        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));
        $keyframe = $shot->keyframes()->firstOrFail();
        $first = $keyframe->render()->id;
        (new TweakKeyframeImage($keyframe, 'Brighter'))->handle(app(KeyframePainter::class));

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $keyframe]), ['render' => $first])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($keyframe->fresh()->render()->id)->toBe($first);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->has('keyframes.0.renders', 2)
                ->where('keyframes.0.renders.0.chosen', true)
                ->where('keyframes.0.renders.1.chosen', false)
                ->where('keyframes.0.renders.1.imageUrl', fn(string $url) => str_contains($url, '?render=')));
    });

    it('rejects a render of another keyframe', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));
        [$first, $second] = $shot->keyframes()->get();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $first]), ['render' => $second->render()->id])
            ->assertSessionHasErrors('render');
    });

    it('streams a specific version', function () {
        Image::fake(fn() => fakePng());

        $shot = plannedShot($this->project);
        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));
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

        expect($shot->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
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

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

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

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

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

        (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

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
