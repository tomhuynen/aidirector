<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineOptionsWriter;
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
use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
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
            ['title' => 'At the mailbox', 'description' => 'The man stands at the mailbox.', 'prompt' => 'A man stands at a red mailbox.', 'elements' => []],
            ['title' => 'Posting', 'description' => 'The envelope slides into the slot.', 'prompt' => 'A man pushes an envelope into the slot.', 'elements' => ['mark, the visitor ']],
            ['title' => 'Thumbs up', 'description' => 'The man gives a thumbs up.', 'prompt' => 'A man gives a thumbs up.', 'elements' => []],
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

        foreach ([new StorylineWriter($shot), new StorylineOptionsWriter($shot)] as $writer) {
            expect((string) $writer->instructions())
                ->toContain('Cast and sets of this project')
                ->toContain('- Mark, the visitor (person): A middle-aged man');
        }

        expect((string) (new StorylineWriter($shot))->instructions())->toContain('never force an existing one into a story where it does not belong')
            ->and((string) (new StorylineOptionsWriter($shot))->instructions())->toContain('Introduce a new person, place or recurring object only when a story needs one worth keeping');

        expect((string) (new StorylineWriter($shot))->instructions())->toContain('Elements: the exact names of the cast and sets listed above');
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
            && $prompt->contains("Cast and sets in this keyframe, draw them exactly as described:\n- Mark, the visitor (person)")
            && $prompt->contains('The first attached image is the reference for Mark, the visitor (person)')
            && $prompt->contains('The second attached image is keyframe 1 of this shot')
            && $prompt->attachments->count() === 2
            && $prompt->attachments->first()->content() === $markBytes);
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
            && $prompt->contains('The third attached image is the reference for Crate 3 (object)')
            && ! $prompt->contains('the reference for Crate 4')
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
            && $prompt->contains('The first attached image is the reference for Security guard (person)'));
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

    it('offers the cast and sets to pick from in the shot brief', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());

        actingAs($this->director, 'director')
            ->get(route('public.shots.create', $this->project))
            ->assertInertia(fn($page) => $page
                ->component('shots/update')
                ->where('elementTypes.0', ['value' => 'person', 'label' => 'Person', 'plural' => 'People'])
                ->has('elementTypes', 3)
                ->where('elements.0.id', $mark->sqid)
                ->where('elements.0.type', 'person')
                ->where('elements.0.name', 'Mark, the visitor')
                ->where('shot.preferredElements', []));
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
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first()->path === $current);
    });

    it('draws a new element from its description with the edit model', function () {
        config(['pipeline.models.image_edit' => 'openai/gpt-5.4-image-2']);
        Image::fake(fn() => elementPng());

        $gate = Element::factory()->for($this->project)->place()->create(['rendering' => true]);

        (new UpdateElementImage($gate))->handle(app(ElementPainter::class));

        expect($gate->fresh())->rendering->toBeFalse()->reference()->not->toBeNull();

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->model === 'openai/gpt-5.4-image-2'
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

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man pushes an envelope into the slot.')
            && $prompt->attachments->first()->path === $first->getPathRelativeToRoot());
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

        $this->project->delete();

        expect(Element::query()->whereKey($mark->id)->exists())->toBeFalse();
    });
});
