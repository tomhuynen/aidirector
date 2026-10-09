<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineWriter;
use App\Ai\ElementPainter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Jobs\GenerateElementReference;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\UpdateElementImage;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Models\Upload;
use Illuminate\Bus\PendingBatch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

function elementPng(): string
{
    static $count = 0;
    $count++;

    $image = imagecreatetruecolor(16 + $count, 9);
    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

function withReference(Element $element): Element
{
    $element->addMediaFromString(base64_decode(elementPng()))->usingFileName('reference.png')->toMediaCollection(Element::REFERENCE);

    return $element->fresh();
}

/**
 * A shot planned with three keyframes; the second names "Mark, the visitor".
 */
function castShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
        'chosen_storyline' => ['title' => 'Straightforward', 'storyline' => 'He posts the letter.'],
        'storyline' => ['keyframes' => [
            ['title' => 'At the mailbox', 'description' => 'A man stands at a red mailbox.', 'elements' => []],
            ['title' => 'Posting', 'description' => 'A man pushes an envelope into the slot.', 'elements' => ['mark, the visitor ']],
            ['title' => 'Thumbs up', 'description' => 'A man gives a thumbs up.', 'elements' => []],
        ]],
        ...$attributes,
    ]);
}

/**
 * Draws the options for keyframe 1 and chooses the first, without detection.
 */
function chooseFirstKeyframe(Shot $shot): Keyframe
{
    (new GenerateKeyframes($shot))->handle();

    $first = $shot->keyframes()->firstOrFail();
    $first->forceFill(['render_id' => $first->renders()->first()->id])->save();

    return $first;
}

describe('writing', function () {
    it('offers the cast and sets to the writers without forcing them', function () {
        Element::factory()->for($this->project)->create();
        $shot = castShot($this->project)->load('project');

        expect((string) (new StorylineWriter($shot))->instructions())
            ->toContain('Cast and sets of this project')
            ->toContain('- Mark, the visitor (person): A middle-aged man');

        expect((string) (new StorylineWriter($shot))->instructions())->toContain('never force an existing one into a story where it does not belong');

        expect((string) (new StorylineWriter($shot))->instructions())->toContain('Elements: the exact names of the cast and sets listed above')->toContain('the spot it came from is now empty');
    });

    it('says when there are no cast and sets yet', function () {
        expect((string) (new StorylineWriter(castShot($this->project)->load('project')))->instructions())->toContain("Cast and sets of this project, recurring people, places and objects:\nNone yet.");
    });
});

describe('linking', function () {
    it('links the elements the plan names and attaches their images', function () {
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create());
        $shot = castShot($this->project);

        $first = chooseFirstKeyframe($shot);
        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        expect($first->elements()->count())->toBe(0)
            ->and($second->elements()->pluck('elements.id')->all())->toBe([$mark->id]);

        $markBytes = Storage::disk(Disk::TENANT->value)->get($mark->reference()->getPathRelativeToRoot());

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man pushes an envelope into the slot.')
            && $prompt->contains("People and objects in this keyframe:\n- Mark, the visitor (person): looks exactly like its attached picture; its state")
            && ! $prompt->contains('A middle-aged man')
            && $prompt->contains('Edit the first attached image. It is keyframe 1 of this shot')
            && $prompt->contains('The second attached image is the picture of Mark, the visitor (person). Draw Mark, the visitor exactly like it')
            && $prompt->attachments->count() === 2
            && $prompt->attachments->last()->content() === $markBytes);
    });

    it('attaches at most three element images to one keyframe', function () {
        Image::fake(fn() => elementPng());

        $shot = castShot($this->project);
        $first = chooseFirstKeyframe($shot);
        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        $elements = collect(range(1, 4))->map(fn(int $n) => withReference(Element::factory()->for($this->project)->object()->create(['name' => "Crate {$n}"])));
        $second->elements()->sync($elements->pluck('id')->all());

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man pushes an envelope into the slot.')
            && $prompt->contains('- Crate 4 (object)')
            && $prompt->contains('The fourth attached image is the picture of Crate 3 (object)')
            && ! $prompt->contains('the picture of Crate 4')
            && $prompt->attachments->count() === 4);
    });
});

describe('reference images', function () {
    it('draws a new element from keyframe 1 before the other keyframes render', function () {
        Image::fake(fn() => elementPng());

        $shot = castShot($this->project);
        $first = chooseFirstKeyframe($shot);
        $guard = Element::factory()->for($this->project)->create(['name' => 'Security guard', 'description' => 'A tall woman in a dark blue uniform.']);
        $first->elements()->attach($guard);
        $shot->keyframes()->where('position', 3)->firstOrFail()->elements()->attach($guard);

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        $firstBytes = Storage::disk(Disk::TENANT->value)->get($first->fresh()->render()->getPathRelativeToRoot());

        expect($guard->fresh()->reference())->not->toBeNull()
            ->and($guard->generations()->where('kind', 'image')->count())->toBe(1);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Show only this person, full body')
            && $prompt->contains('Security guard (person): A tall woman in a dark blue uniform.')
            && $prompt->contains('The attached image is a keyframe in which Security guard appears.')
            && $prompt->size === '1:1'
            && $prompt->attachments->first()->content() === $firstBytes);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man gives a thumbs up.')
            && $prompt->contains('attached image is the picture of Security guard (person)'));
    });

    it('draws new element images side by side before the keyframes', function () {
        Image::fake(fn() => elementPng());

        $shot = castShot($this->project);
        $first = chooseFirstKeyframe($shot);
        $guard = Element::factory()->for($this->project)->create(['name' => 'Security guard']);
        $crate = Element::factory()->for($this->project)->object()->create();
        $first->elements()->attach($guard);
        $shot->keyframes()->where('position', 3)->firstOrFail()->elements()->attach($crate);

        Bus::fake();

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        Bus::assertBatched(fn(PendingBatch $batch) => $batch->jobs->count() === 2
            && $batch->allowsFailures()
            && $batch->jobs->first(fn(GenerateElementReference $job) => $job->element->is($guard))?->source?->is($first)
            && $batch->jobs->first(fn(GenerateElementReference $job) => $job->element->is($crate))?->source === null);

        expect($shot->keyframes()->where('position', 2)->firstOrFail()->renders())->toHaveCount(0);
    });

    it('does not fail the batch when an element image fails', function () {
        Image::fake(fn() => throw new RuntimeException('Provider down'));

        $crate = Element::factory()->for($this->project)->object()->create();

        (new GenerateElementReference($crate))->handle(app(ElementPainter::class));

        expect($crate->fresh()->reference())->toBeNull()
            ->and($crate->generations()->whereNotNull('error')->count())->toBe(1);
    });

    it('keeps rendering the keyframes when an element image fails', function () {
        Image::fake(function (ImagePrompt $prompt) {
            if ($prompt->contains('Show only this object')) {
                throw new RuntimeException('Provider down');
            }

            return elementPng();
        });

        $shot = castShot($this->project);
        chooseFirstKeyframe($shot);
        $crate = Element::factory()->for($this->project)->object()->create();
        $shot->keyframes()->where('position', 2)->firstOrFail()->elements()->attach($crate);

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($crate->fresh()->reference())->toBeNull()
            ->and($crate->generations()->whereNotNull('error')->count())->toBe(1);
    });
});

describe('pages', function () {
    it('shows the elements per keyframe in the editor', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        $shot = castShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        Keyframe::factory()->for($shot)->create()->elements()->attach($mark);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->missing('shot.elementProposals')
                ->where('keyframes.0.elements', ['Mark, the visitor']));
    });

    it('leaves picking the cast and sets to the plan chat, not the shot brief', function () {
        withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->get(route('public.shots.create', $this->project))
            ->assertInertia(fn($page) => $page
                ->component('shots/create')
                ->missing('elements')
                ->missing('elementTypes'));
    });

    it('lists the cast and sets on the project overview', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());

        $response = actingAs($this->director, 'director')
            ->get(route('public.projects.view', $this->project))
            ->assertInertia(fn($page) => $page
                ->has('elements', 1)
                ->where('elements.0.name', 'Mark, the visitor')
                ->where('elements.0.shots', []));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['elements'][0]['imageUrl'])
            ->assertSuccessful();
    });
});

describe('adding', function () {
    it('adds an element to a category, draws its image and opens its page', function () {
        Queue::fake();

        $response = actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'place', 'name' => 'Main gate', 'description' => 'A grey gatehouse with a yellow barrier.']);

        $gate = Element::query()->where('name', 'Main gate')->firstOrFail();

        $response->assertRedirect(route('public.projects.elements.view', [$this->project, $gate]));

        expect($gate->type)->toBe(ElementType::PLACE)
            ->and($gate->rendering)->toBeTrue()
            ->and($gate->project_id)->toBe($this->project->id);

        Queue::assertPushed(UpdateElementImage::class, fn(UpdateElementImage $job) => $job->element->is($gate) && $job->instruction === null);
    });

    it('keeps an uploaded photo on the element and draws its picture from it', function () {
        Storage::fake(config('uploads.disk'));
        Image::fake(fn() => elementPng());

        $upload = Upload::factory()->create();
        Storage::disk(config('uploads.disk'))->put($upload->path, base64_decode(elementPng()));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'object', 'name' => 'Life jacket', 'description' => 'An orange life jacket.', 'photo' => $upload->sqid])
            ->assertSessionHasNoErrors();

        $jacket = Element::query()->where('name', 'Life jacket')->firstOrFail();

        expect($jacket->getFirstMedia(Element::PHOTO))->not->toBeNull()
            ->and(Upload::query()->find($upload->id))->toBeNull()
            ->and($jacket->fresh()->reference())->not->toBeNull();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('is a photo of the real Life jacket. Use it only for what it is: shapes, markings and colours.')
            && $prompt->contains('Do not copy its realism, lighting or proportions')
            && $prompt->attachments->count() >= 1);
    });

    it('draws chosen elements into the new one', function () {
        Image::fake(fn() => elementPng());

        $briefcase = withReference(Element::factory()->for($this->project)->create(['type' => 'object', 'name' => 'Navy briefcase', 'description' => 'A navy briefcase.']));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'person', 'name' => 'CEO', 'description' => 'A CEO in a suit.', 'includes' => [$briefcase->sqid]])
            ->assertSessionHasNoErrors();

        $briefcaseBytes = Storage::disk(Disk::TENANT->value)->get($briefcase->reference()->getPathRelativeToRoot());

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Show these together with CEO, as part of it, and nothing else: Navy briefcase.')
            && $prompt->contains('attached image is Navy briefcase (object). Draw it exactly like it')
            && $prompt->attachments->contains(fn($image) => $image->content() === $briefcaseBytes));
    });

    it('rejects elements from another project to draw in', function () {
        Queue::fake();
        $foreign = Element::factory()->for(Project::factory())->create();

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'person', 'name' => 'CEO', 'description' => 'A CEO.', 'includes' => [$foreign->sqid]])
            ->assertSessionHasErrors('includes');

        expect(Element::query()->where('name', 'CEO')->exists())->toBeFalse();
    });

    it('offers the cast and sets to draw in on the create page', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.create', [$this->project, 'type' => 'person']))
            ->assertInertia(fn($page) => $page->where('elements.0.id', $mark->sqid)->has('elementTypes', 3));
    });

    it('draws picked elements into the picture through a change', function () {
        config(['pipeline.models.image_edit' => 'openai/gpt-5.4-image-2']);
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create());
        $briefcase = withReference(Element::factory()->for($this->project)->create(['type' => 'object', 'name' => 'Navy briefcase', 'description' => 'A navy briefcase.']));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.update', [$this->project, $mark]), ['name' => $mark->name, 'description' => $mark->description, 'change' => '', 'includes' => [$briefcase->sqid]])
            ->assertSessionHasNoErrors();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'openai/gpt-5.4-image-2'
            && $prompt->contains('Edit the first attached image.')
            && $prompt->contains('Change only this: Add Navy briefcase to it.')
            && $prompt->contains('The second attached image is Navy briefcase (object). Draw it into the picture exactly like it')
            && $prompt->attachments->count() === 2);
    });

    it('matches the style of a cast member already drawn, chosen by itself', function () {
        Image::fake(fn() => elementPng());

        $engineer = withReference(Element::factory()->for($this->project)->create(['name' => 'Female engineer']));
        withReference(Element::factory()->for($this->project)->place()->create());
        $ceo = Element::factory()->for($this->project)->create(['name' => 'CEO', 'rendering' => true]);

        (new UpdateElementImage($ceo))->handle(app(ElementPainter::class));

        $anchorBytes = Storage::disk(Disk::TENANT->value)->get($engineer->reference()->getPathRelativeToRoot());

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('The first attached image is Female engineer, already drawn for this project. Draw CEO in exactly the same style')
            && $prompt->contains("Do not copy Female engineer's face, clothing or pose.")
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->content() === $anchorBytes);
    });

    it('draws a person from a photo in two steps: a drawing first, then the project style', function () {
        Storage::fake(config('uploads.disk'));
        config(['pipeline.models.photo_drawing' => 'bytedance-seed/seedream-5-0-flash', 'pipeline.models.image' => 'google/gemini-3.1-flash-image-preview']);
        Image::fake(fn() => elementPng());
        $drawing = elementPng();
        Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => $drawing, 'media_type' => 'image/png']], 'usage' => ['cost' => 0.018]])]);

        withReference(Element::factory()->for($this->project)->create(['name' => 'Female engineer']));
        $upload = Upload::factory()->create();
        Storage::disk(config('uploads.disk'))->put($upload->path, base64_decode(elementPng()));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'person', 'name' => 'Tom', 'description' => 'A bald man in a black T-shirt.', 'photo' => $upload->sqid])
            ->assertSessionHasNoErrors();

        $tom = Element::query()->where('name', 'Tom')->firstOrFail();

        Http::assertSent(fn(HttpRequest $request) => $request['model'] === 'bytedance-seed/seedream-5-0-flash'
            && str_contains($request['prompt'], 'realistic adult proportions')
            && count($request['input_references']) === 1);

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'google/gemini-3.1-flash-image-preview'
            && $prompt->contains('attached image is a drawing of Tom. Take only who they are from it')
            && $prompt->contains('Take the proportions, head size and body shape from Female engineer')
            && $prompt->attachments->contains(fn($image) => $image->content() === base64_decode($drawing)));

        expect($tom->generations()->where('model', 'bytedance-seed/seedream-5-0-flash')->first()->usage)->toBe(['cost' => 0.018])
            ->and($tom->fresh()->reference())->not->toBeNull();
    });

    it('draws a place or object from a photo in one step', function () {
        Storage::fake(config('uploads.disk'));
        Image::fake(fn() => elementPng());
        Http::fake();

        $upload = Upload::factory()->create();
        Storage::disk(config('uploads.disk'))->put($upload->path, base64_decode(elementPng()));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'place', 'name' => 'Quay', 'description' => 'A concrete quay.', 'photo' => $upload->sqid])
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('is a photo of the real Quay. Use it only for what it is'));
    });

    it('rejects a photo that is not a staged image', function () {
        Queue::fake();

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'object', 'name' => 'Crate', 'description' => 'A crate.', 'photo' => 'upl_missing'])
            ->assertSessionHasErrors('photo');

        expect(Element::query()->where('name', 'Crate')->exists())->toBeFalse();
    });

    it('validates the category, name and description', function () {
        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $this->project), ['type' => 'vehicle', 'name' => '', 'description' => ''])
            ->assertSessionHasErrors(['type', 'name', 'description']);
    });

    it('forbids adding to another director\'s project', function () {
        $project = Project::factory()->create();

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.store', $project), ['type' => 'person', 'name' => 'Guard', 'description' => 'A guard.'])
            ->assertForbidden();
    });

    it('lists the categories on the overview', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $this->project))
            ->assertInertia(fn($page) => $page
                ->where('elementTypes', [
                    ['value' => 'person', 'label' => 'Person', 'plural' => 'People'],
                    ['value' => 'place', 'label' => 'Place', 'plural' => 'Places'],
                    ['value' => 'object', 'label' => 'Object', 'plural' => 'Objects'],
                ])
                ->where('project.links.elementsStore', route('public.projects.elements.store', $this->project)));
    });
});

describe('deleting', function () {
    it('deletes an element with its pictures and keeps the keyframes that showed it', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        $shot = castShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($shot)->create();
        $keyframe->elements()->attach($mark);
        $picture = $mark->reference()->getPathRelativeToRoot();

        actingAs($this->director, 'director')
            ->delete(route('public.projects.elements.destroy', [$this->project, $mark]))
            ->assertRedirect(route('public.projects.view', $this->project));

        expect(Element::query()->find($mark->id))->toBeNull()
            ->and($keyframe->fresh())->not->toBeNull()
            ->and($keyframe->elements()->count())->toBe(0);

        Storage::disk(Disk::TENANT->value)->assertMissing($picture);
    });

    it('forbids deleting another director\'s element', function () {
        $project = Project::factory()->create();
        $element = Element::factory()->for($project)->create();

        actingAs($this->director, 'director')
            ->delete(route('public.projects.elements.destroy', [$project, $element]))
            ->assertForbidden();

        expect(Element::query()->find($element->id))->not->toBeNull();
    });
});

describe('element page', function () {
    it('opens an empty page to create an element of a type', function () {
        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.create', [$this->project, 'type' => 'object']))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->component('elements/edit')
                ->where('element', null)
                ->where('type', ['value' => 'object', 'label' => 'Object', 'plural' => 'Objects'])
                ->where('saveUrl', route('public.projects.elements.store', $this->project)));
    });

    it('shows an element with its picture and form', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.view', [$this->project, $mark]))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->component('elements/edit')
                ->where('element.id', $mark->sqid)
                ->where('element.name', 'Mark, the visitor')
                ->where('element.rendering', false)
                ->where('element.imageUrl', fn(string $url) => str_contains($url, 'signature='))
                ->where('type.value', 'person')
                ->where('saveUrl', route('public.projects.elements.update', [$this->project, $mark])));
    });

    it('saves a new name without drawing the picture again', function () {
        Queue::fake();
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.update', [$this->project, $mark]), ['name' => 'Mark', 'description' => $mark->description, 'change' => ''])
            ->assertRedirect(route('public.projects.elements.view', [$this->project, $mark]));

        expect($mark->fresh())->name->toBe('Mark')->rendering->toBeFalse();
        Queue::assertNotPushed(UpdateElementImage::class);
    });

    it('edits the picture with the requested change', function () {
        Queue::fake();
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.update', [$this->project, $mark]), ['name' => $mark->name, 'description' => $mark->description, 'change' => 'Give him glasses']);

        expect($mark->fresh()->rendering)->toBeTrue();
        Queue::assertPushed(UpdateElementImage::class, fn(UpdateElementImage $job) => $job->element->is($mark) && $job->instruction === 'Give him glasses');
    });

    it('draws the picture again when the description changes', function () {
        Queue::fake();
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.update', [$this->project, $mark]), ['name' => $mark->name, 'description' => 'A young man in a grey suit.']);

        expect($mark->fresh())->description->toBe('A young man in a grey suit.')->rendering->toBeTrue();
        Queue::assertPushed(UpdateElementImage::class, fn(UpdateElementImage $job) => $job->instruction === null);
    });

    it('edits the current picture with the edit model', function () {
        config(['pipeline.models.image_edit' => 'openai/gpt-5.4-image-2']);
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create(['rendering' => true]));
        $current = $mark->reference()->getPathRelativeToRoot();

        (new UpdateElementImage($mark, 'Give him glasses'))->handle(app(ElementPainter::class));

        expect($mark->fresh())->rendering->toBeFalse()->render_error->toBeNull();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'openai/gpt-5.4-image-2'
            && $prompt->contains('Change only this: Give him glasses')
            && $prompt->contains(ElementPainter::NO_TEXT)
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $current);
    });

    it('draws a new element from its description with the image model', function () {
        config(['pipeline.models.image' => 'google/gemini-3.1-flash-image-preview']);
        Image::fake(fn() => elementPng());

        $gate = Element::factory()->for($this->project)->place()->create(['rendering' => true]);

        (new UpdateElementImage($gate))->handle(app(ElementPainter::class));

        expect($gate->fresh())->rendering->toBeFalse()->reference()->not->toBeNull();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'google/gemini-3.1-flash-image-preview'
            && $prompt->contains('not even words from the description')
            && $prompt->contains('Show only this place')
            && $prompt->contains('Main gate (place)'));
    });

    it('reports a failed picture on the page with the reason', function () {
        $mark = Element::factory()->for($this->project)->create(['rendering' => true]);

        (new UpdateElementImage($mark, 'Anything'))->failed(new RuntimeException('Provider down'));

        expect($mark->fresh())->rendering->toBeFalse()
            ->render_error->toBe('The image could not be generated. Please try again. Provider down');
    });

    it('never lets a stale attempt overwrite a picture that was drawn', function () {
        $mark = Element::factory()->for($this->project)->create(['rendering' => false, 'render_error' => null]);

        (new UpdateElementImage($mark, 'Anything'))->failed(new MaxAttemptsExceededException('App\\Jobs\\UpdateElementImage has been attempted too many times.'));

        expect($mark->fresh())->rendering->toBeFalse()->render_error->toBeNull();
    });

    it('explains a job that was stopped for running too long', function () {
        $mark = Element::factory()->for($this->project)->create(['rendering' => true]);

        (new UpdateElementImage($mark, 'Anything'))->failed(new MaxAttemptsExceededException('attempted too many times'));

        expect($mark->fresh()->render_error)->toEndWith('It ran longer than the queue allows and was stopped.');
    });

    it('keeps elements to their own project and director', function () {
        $mark = Element::factory()->for($this->project)->create();
        $otherProject = Project::factory()->ownedBy($this->director)->create();
        $foreign = Element::factory()->create();

        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.view', [$otherProject, $mark]))
            ->assertNotFound();

        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.view', [$foreign->project, $foreign]))
            ->assertForbidden();

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.update', [$foreign->project, $foreign]), ['name' => 'X', 'description' => 'Y'])
            ->assertForbidden();
    });
});

describe('versions', function () {
    it('keeps the earlier picture and records the change on the new version', function () {
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create(['rendering' => true]));
        $first = $mark->reference();

        (new UpdateElementImage($mark, 'Give him glasses'))->handle(app(ElementPainter::class));

        $mark->refresh();

        expect($mark->references())->toHaveCount(2)
            ->and($mark->reference()->id)->not->toBe($first->id)
            ->and($mark->reference_id)->toBe($mark->reference()->id)
            ->and($mark->reference()->getCustomProperty(Element::CHANGE_REQUEST))->toBe('Give him glasses')
            ->and($mark->references()->first()->id)->toBe($first->id);
    });

    it('makes an earlier version the chosen picture again', function () {
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create());
        $first = $mark->reference();
        (new UpdateElementImage($mark, 'Give him glasses'))->handle(app(ElementPainter::class));

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.version', [$this->project, $mark]), ['version' => $first->id])
            ->assertRedirect(route('public.projects.elements.view', [$this->project, $mark]));

        expect($mark->fresh()->reference()->id)->toBe($first->id);

        actingAs($this->director, 'director')
            ->get(route('public.projects.elements.view', [$this->project, $mark]))
            ->assertInertia(fn($page) => $page
                ->has('element.versions', 2)
                ->where('element.versions.0.chosen', true)
                ->where('element.versions.1.chosen', false)
                ->where('element.versions.1.request', 'Give him glasses')
                ->where('element.versionUrl', route('public.projects.elements.version', [$this->project, $mark])));
    });

    it('only chooses versions of the same element', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        $gate = withReference(Element::factory()->for($this->project)->place()->create());

        actingAs($this->director, 'director')
            ->post(route('public.projects.elements.version', [$this->project, $mark]), ['version' => $gate->reference()->id])
            ->assertSessionHasErrors('version');
    });

    it('attaches the chosen version to keyframe renders', function () {
        Image::fake(fn() => elementPng());

        $mark = withReference(Element::factory()->for($this->project)->create());
        $first = $mark->reference();
        (new UpdateElementImage($mark, 'Give him glasses'))->handle(app(ElementPainter::class));
        $mark->forceFill(['reference_id' => $first->id])->save();

        $shot = castShot($this->project);
        chooseFirstKeyframe($shot);
        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class));

        // Keyframe 1 comes first as the base image; the chosen picture of the element follows it.
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man pushes an envelope into the slot.')
            && $prompt->attachments->get(1)?->path === $first->getPathRelativeToRoot());
    });
});

describe('cleanup', function () {
    it('unlinks a keyframe when it is deleted and keeps the element', function () {
        $mark = Element::factory()->for($this->project)->create();
        $keyframe = Keyframe::factory()->for(castShot($this->project))->create();
        $keyframe->elements()->attach($mark);

        $keyframe->delete();

        expect($mark->fresh())->not->toBeNull()
            ->and($mark->keyframes()->count())->toBe(0);
    });

    it('removes the cast and sets with the project', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        Keyframe::factory()->for(castShot($this->project))->create()->elements()->attach($mark);

        $this->project->forceDelete();

        expect(Element::query()->whereKey($mark->id)->exists())->toBeFalse();
    });
});
