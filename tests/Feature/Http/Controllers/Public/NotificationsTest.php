<?php

declare(strict_types=1);

use App\Ai\ElementPainter;
use App\Enums\Disk;
use App\Jobs\GenerateVideo;
use App\Jobs\UpdateElementImage;
use App\Models\Director;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

describe('sending', function () {
    it('tells the director when a generation failed', function () {
        $shot = Shot::factory()->for($this->project)->create(['title' => 'No smoking']);

        (new GenerateVideo($shot))->failed(new RuntimeException('Provider down'));

        expect($this->director->notifications()->sole()->data)
            ->title->toBe('The video of “No smoking” could not be started')
            ->failed->toBeTrue();
    });

    it('keeps a picture of the result, signed fresh whenever the list is read', function () {
        Image::fake([base64_encode(UploadedFile::fake()->image('gate.png', 32, 32)->getContent())]);
        $gate = Element::factory()->for($this->project)->place()->create(['name' => 'Main gate', 'rendering' => true]);

        (new UpdateElementImage($gate))->handle(app(ElementPainter::class));

        expect($this->director->notifications()->sole()->data)
            ->title->toBe('The image of “Main gate” is ready')
            ->url->toBe(route('public.projects.elements.view', [$this->project, $gate]))
            ->image->toBe(['id' => $gate->fresh()->reference()->id, 'conversion' => Element::THUMBNAIL]);

        actingAs($this->director, 'director')
            ->getJson(route('public.notifications.index'))
            ->assertJsonPath('notifications.0.imageUrl', fn(string $url) => str_contains($url, 'signature='));
    });
});

describe('reading', function () {
    it('lists the latest notifications with the unread count', function () {
        GenerationFinished::ready('Older', 'https://example.test/a')->sendTo($this->project);
        $this->travel(1)->minute();
        GenerationFinished::failed('Newer', 'https://example.test/b')->sendTo($this->project);

        actingAs($this->director, 'director')
            ->getJson(route('public.notifications.index'))
            ->assertSuccessful()
            ->assertJsonPath('unread', 2)
            ->assertJsonPath('notifications.0.title', 'Newer')
            ->assertJsonPath('notifications.0.failed', true)
            ->assertJsonPath('notifications.0.imageUrl', null)
            ->assertJsonPath('notifications.1.title', 'Older')
            ->assertJsonPath('notifications.1.read', false)
            ->assertJsonStructure(['now']);
    });

    it('returns only what arrived since the last poll, including that second', function () {
        GenerationFinished::ready('Before', 'https://example.test/a')->sendTo($this->project);
        $this->travel(10)->seconds();
        $now = now()->toIso8601String();
        GenerationFinished::ready('Same second', 'https://example.test/b')->sendTo($this->project);
        $this->travel(5)->seconds();
        GenerationFinished::ready('After', 'https://example.test/c')->sendTo($this->project);

        actingAs($this->director, 'director')
            ->getJson(route('public.notifications.index', ['after' => $now]))
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.title', 'After')
            ->assertJsonPath('notifications.1.title', 'Same second')
            ->assertJsonPath('unread', 3);
    });

    it('only shows a director their own notifications', function () {
        GenerationFinished::ready('Mine', 'https://example.test/a')->sendTo($this->project);

        actingAs(Director::factory()->create(), 'director')
            ->getJson(route('public.notifications.index'))
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'notifications');
    });

    it('marks the given notifications or all of them as read', function () {
        GenerationFinished::ready('One', 'https://example.test/a')->sendTo($this->project);
        GenerationFinished::ready('Two', 'https://example.test/b')->sendTo($this->project);
        GenerationFinished::ready('Three', 'https://example.test/c')->sendTo($this->project);
        $first = $this->director->notifications()->where('data->title', 'One')->sole();

        actingAs($this->director, 'director')
            ->postJson(route('public.notifications.read'), ['ids' => [$first->id]])
            ->assertSuccessful()
            ->assertJsonPath('unread', 2);

        expect($first->fresh()->read_at)->not->toBeNull();

        actingAs($this->director, 'director')
            ->postJson(route('public.notifications.read'))
            ->assertJsonPath('unread', 0);
    });

    it('requires a login', function () {
        $this->getJson(route('public.notifications.index'))->assertUnauthorized();
        $this->postJson(route('public.notifications.read'))->assertUnauthorized();
    });
});
