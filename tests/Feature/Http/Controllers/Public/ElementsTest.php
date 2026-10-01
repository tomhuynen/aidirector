<?php

declare(strict_types=1);

use App\Ai\Agents\ElementDetector;
use App\Ai\Agents\StorylineOptionsWriter;
use App\Ai\Agents\StorylineWriter;
use App\Ai\ElementPainter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotStatus;
use App\Jobs\DetectElements;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Models\Director;
use App\Models\Element;
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
    (new GenerateKeyframes($shot))->handle(app(KeyframePainter::class));

    $first = $shot->keyframes()->firstOrFail();
    $first->forceFill(['render_id' => $first->renders()->first()->id])->save();

    return $first;
}

function detectorProposals(): array
{
    return ['elements' => [
        ['name' => 'Security guard', 'type' => 'person', 'description' => 'A tall woman in a dark blue uniform and cap.', 'keyframes' => [1, 3, 9], 'match' => ''],
        ['name' => 'Visitor in navy suit', 'type' => 'person', 'description' => 'A man in a navy suit.', 'keyframes' => [1], 'match' => 'Mark, the visitor'],
        ['name' => 'Red mailbox', 'type' => 'object', 'description' => 'A red steel mailbox on a post.', 'keyframes' => [], 'match' => ''],
        ['name' => 'Spaceship', 'type' => 'vehicle', 'description' => 'Not a valid type.', 'keyframes' => [1], 'match' => ''],
    ]];
}

describe('writing', function () {
    it('offers the cast and sets to the writers without forcing them', function () {
        Element::factory()->for($this->project)->create();
        $shot = castShot($this->project)->load('project');

        foreach ([new StorylineWriter($shot), new StorylineOptionsWriter($shot)] as $writer) {
            expect((string) $writer->instructions())
                ->toContain('Cast and sets of this project')
                ->toContain('- Mark, the visitor (person): A middle-aged man')
                ->toContain('never force an existing one into a story where it does not belong');
        }

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
        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(ElementPainter::class));

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

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(ElementPainter::class));

        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A man pushes an envelope into the slot.')
            && $prompt->contains('- Crate 4 (object)')
            && $prompt->contains('The third attached image is the reference for Crate 3 (object)')
            && ! $prompt->contains('the reference for Crate 4')
            && $prompt->attachments->count() === 4);
    });
});

describe('detection', function () {
    it('proposes new elements and near misses for review', function () {
        ElementDetector::fake([detectorProposals()]);

        $mark = Element::factory()->for($this->project)->create();
        $shot = castShot($this->project);
        Image::fake(fn() => elementPng());
        chooseFirstKeyframe($shot);

        (new DetectElements($shot))->handle(app(KeyframePainter::class));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::ELEMENTS_READY)
            ->and($shot->elementProposals())->toEqual([
                ['name' => 'Security guard', 'type' => 'person', 'description' => 'A tall woman in a dark blue uniform and cap.', 'keyframes' => [1, 3], 'match' => null],
                ['name' => 'Visitor in navy suit', 'type' => 'person', 'description' => 'A man in a navy suit.', 'keyframes' => [1], 'match' => $mark->sqid],
                ['name' => 'Red mailbox', 'type' => 'object', 'description' => 'A red steel mailbox on a post.', 'keyframes' => [1], 'match' => null],
            ]);

        ElementDetector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '2. Posting: The envelope slides into the slot. Named elements: Mark, the visitor.')
            && str_contains($prompt->prompt, '- Mark, the visitor (person)'));
    });

    it('goes straight on to the other keyframes when there is nothing to review', function () {
        ElementDetector::fake([['elements' => []]]);
        Image::fake(fn() => elementPng());

        $shot = castShot($this->project);
        chooseFirstKeyframe($shot);
        Queue::fake();

        (new DetectElements($shot))->handle(app(KeyframePainter::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING);
        Queue::assertPushed(GenerateRemainingKeyframes::class, fn(GenerateRemainingKeyframes $job) => $job->shot->is($shot));
    });

    it('does not block rendering when detection fails', function () {
        Queue::fake();

        $shot = castShot($this->project, ['status' => ShotStatus::ELEMENTS_PENDING]);

        (new DetectElements($shot))->failed(new RuntimeException('Provider down'));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($shot->generations()->whereNotNull('error')->count())->toBe(1);
        Queue::assertPushed(GenerateRemainingKeyframes::class);
    });
});

describe('review', function () {
    beforeEach(function () {
        $this->mark = Element::factory()->for($this->project)->create();
        $this->shot = castShot($this->project, ['status' => ShotStatus::ELEMENTS_READY, 'element_proposals' => [
            ['name' => 'Security guard', 'type' => 'person', 'description' => 'A tall woman in a dark blue uniform.', 'keyframes' => [1, 3], 'match' => null],
            ['name' => 'Visitor in navy suit', 'type' => 'person', 'description' => 'A man in a navy suit.', 'keyframes' => [1], 'match' => $this->mark->sqid],
            ['name' => 'Red mailbox', 'type' => 'object', 'description' => 'A red steel mailbox.', 'keyframes' => [1], 'match' => null],
        ]]);

        foreach (range(1, 3) as $position) {
            Keyframe::factory()->for($this->shot)->create(['position' => $position]);
        }

        Queue::fake();
    });

    it('adds, swaps and skips as the director decides, then renders the rest', function () {
        actingAs($this->director, 'director')
            ->post(route('public.shots.elements.review', [$this->project, $this->shot]), ['decisions' => [
                ['action' => 'add'],
                ['action' => 'existing', 'element' => $this->mark->sqid],
                ['action' => 'skip'],
            ]])
            ->assertRedirect(route('public.shots.view', [$this->project, $this->shot]));

        $guard = Element::query()->where('name', 'Security guard')->firstOrFail();
        [$first, , $third] = $this->shot->keyframes()->get();

        expect($guard->type)->toBe(ElementType::PERSON)
            ->and($guard->project_id)->toBe($this->project->id)
            ->and($first->elements()->pluck('name')->sort()->values()->all())->toBe(['Mark, the visitor', 'Security guard'])
            ->and($third->elements()->pluck('name')->all())->toBe(['Security guard'])
            ->and(Element::query()->where('name', 'Red mailbox')->exists())->toBeFalse()
            ->and($this->shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($this->shot->fresh()->element_proposals)->toBeNull();

        Queue::assertPushed(GenerateRemainingKeyframes::class);
    });

    it('needs a decision for every proposal', function () {
        actingAs($this->director, 'director')
            ->post(route('public.shots.elements.review', [$this->project, $this->shot]), ['decisions' => [['action' => 'skip']]])
            ->assertSessionHasErrors('decisions');

        Queue::assertNothingPushed();
    });

    it('only swaps to elements of the same project', function () {
        $foreign = Element::factory()->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.elements.review', [$this->project, $this->shot]), ['decisions' => [
                ['action' => 'existing', 'element' => $foreign->sqid],
                ['action' => 'skip'],
                ['action' => 'skip'],
            ]])
            ->assertSessionHasErrors('decisions.0.element');

        expect($this->shot->keyframes()->first()->elements()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('only reviews while proposals are waiting', function () {
        $this->shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.elements.review', [$this->project, $this->shot]), ['decisions' => [['action' => 'skip'], ['action' => 'skip'], ['action' => 'skip']]])
            ->assertSessionHasErrors('decisions');
    });

    it('forbids reviewing another director\'s shot', function () {
        $shot = castShot(Project::factory()->create(), ['status' => ShotStatus::ELEMENTS_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.elements.review', [$shot->project, $shot]), ['decisions' => [['action' => 'skip']]])
            ->assertForbidden();
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

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(ElementPainter::class));

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

        (new GenerateRemainingKeyframes($shot))->handle(app(KeyframePainter::class), app(ElementPainter::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($crate->fresh()->reference())->toBeNull()
            ->and($crate->generations()->whereNotNull('error')->count())->toBe(1);
    });
});

describe('pages', function () {
    it('shows the proposals, the library and the elements per keyframe in the editor', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        $shot = castShot($this->project, ['status' => ShotStatus::ELEMENTS_READY, 'element_proposals' => [
            ['name' => 'Security guard', 'type' => 'person', 'description' => 'A tall woman.', 'keyframes' => [1], 'match' => null],
        ]]);
        Keyframe::factory()->for($shot)->create()->elements()->attach($mark);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('shot.elementProposals.0.name', 'Security guard')
                ->where('shot.links.elementsReview', route('public.shots.elements.review', [$this->project, $shot]))
                ->where('elements.0.id', $mark->sqid)
                ->where('elements.0.typeLabel', 'Person')
                ->where('elements.0.imageUrl', fn(string $url) => str_contains($url, 'signature='))
                ->where('keyframes.0.elements', ['Mark, the visitor']));
    });

    it('lists the cast and sets on the project overview with the shots they are in', function () {
        $mark = withReference(Element::factory()->for($this->project)->create());
        $shot = castShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY, 'position' => 2]);
        Keyframe::factory()->for($shot)->create(['position' => 1])->elements()->attach($mark);
        Keyframe::factory()->for($shot)->create(['position' => 2])->elements()->attach($mark);

        $response = actingAs($this->director, 'director')
            ->get(route('public.projects.view', $this->project))
            ->assertInertia(fn($page) => $page
                ->has('elements', 1)
                ->where('elements.0.name', 'Mark, the visitor')
                ->where('elements.0.shots', [['position' => 2, 'title' => $shot->title, 'url' => route('public.shots.view', [$this->project, $shot])]]));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['elements'][0]['imageUrl'])
            ->assertSuccessful();
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
