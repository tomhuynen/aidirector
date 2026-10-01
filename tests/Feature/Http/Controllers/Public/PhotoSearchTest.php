<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Enums\Disk;
use App\Models\Director;
use App\Models\Generation;
use App\Models\PhotoSuggestion;
use App\Models\Project;
use App\Models\Upload;
use App\Support\PhotoSearch\SuggestPhotos;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Config::set('pipeline.photo_search.api_key', 'serper-key');

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['website' => 'https://www.damen.com/']);
});

/**
 * @return array<string, mixed>
 */
function serperImage(string $url, int $width = 1600, int $height = 900, string $domain = 'www.damen.com'): array
{
    return [
        'title' => 'Photo ' . basename($url),
        'imageUrl' => $url,
        'imageWidth' => $width,
        'imageHeight' => $height,
        'thumbnailUrl' => $url . '?thumb',
        'domain' => $domain,
        'link' => "https://{$domain}/page",
    ];
}

describe('suggesting', function () {
    it('searches the website first, tops up from the web and drops small, off-site and duplicate images', function () {
        Config::set('pipeline.photo_search.per_query', 3);

        Http::fake(function (Request $request) {
            return match ($request['q']) {
                'site:damen.com shipyard gate' => Http::response(['images' => [
                    serperImage('https://93.184.216.34/gate.jpg'),
                    serperImage('https://93.184.216.34/icon.png', 120, 120),
                    serperImage('https://93.184.216.34/offsite.jpg', domain: 'news.example'),
                ]]),
                'shipyard gate' => Http::response(['images' => [
                    serperImage('https://93.184.216.34/gate.jpg'),
                    serperImage('https://93.184.216.34/other-gate.jpg', domain: 'stock.example'),
                    serperImage('https://93.184.216.34/third.jpg', domain: 'stock.example'),
                    serperImage('https://93.184.216.34/fourth.jpg', domain: 'stock.example'),
                ]]),
                default => Http::response('nope', 500),
            };
        });

        $suggestions = app(SuggestPhotos::class)->suggest($this->project, ['shipyard gate', 'Stan Tug', 'shipyard gate']);

        expect($suggestions->pluck('image_url')->all())->toBe([
            'https://93.184.216.34/gate.jpg',
            'https://93.184.216.34/other-gate.jpg',
            'https://93.184.216.34/third.jpg',
        ])
            ->and($suggestions[0]->from_website)->toBeTrue()
            ->and($suggestions[1]->from_website)->toBeFalse()
            ->and($suggestions->pluck('batch')->unique()->all())->toBe([1])
            ->and($suggestions->pluck('position')->all())->toBe([1, 2, 3]);

        Http::assertSentCount(4);
        Http::assertSent(fn(Request $request) => $request->hasHeader('X-API-KEY', 'serper-key') && $request['q'] === 'site:damen.com Stan Tug');

        $generation = Generation::query()->where('kind', 'search')->firstOrFail();

        expect($generation->provider)->toBe('serper')
            ->and((float) $generation->cost)->toBe(0.004);
    });

    it('searches only the web without a website and never repeats earlier suggestions', function () {
        $this->project->update(['website' => null]);
        PhotoSuggestion::factory()->for($this->project)->create(['image_url' => 'https://93.184.216.34/seen.jpg']);

        Http::fake(['*' => Http::response(['images' => [
            serperImage('https://93.184.216.34/seen.jpg'),
            serperImage('https://93.184.216.34/new.jpg'),
        ]])]);

        $suggestions = app(SuggestPhotos::class)->suggest($this->project, ['tug']);

        expect($suggestions->pluck('image_url')->all())->toBe(['https://93.184.216.34/new.jpg'])
            ->and($suggestions[0]->batch)->toBe(2);

        Http::assertSentCount(1);
    });
});

describe('chat', function () {
    it('keeps the website and shows a gallery when the agent asks for photo searches', function () {
        Http::fake(['google.serper.dev/*' => Http::response(['images' => [serperImage('https://93.184.216.34/gate.jpg')]])]);
        ProjectIntake::fake([
            ['reply' => 'Hi', 'description' => 'd', 'purpose' => 'e-learning', 'title' => 'Damen', 'website' => null, 'photo_searches' => [], 'ask' => null, 'done' => false],
            ['reply' => 'I looked on damen.com. Tick what fits.', 'description' => 'd', 'purpose' => 'e-learning', 'title' => 'Damen', 'website' => 'damen.com', 'photo_searches' => ['shipyard gate'], 'ask' => 'photos', 'done' => false],
        ]);

        $conversationId = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'Damen'])
            ->assertSuccessful()
            ->assertJsonPath('gallery', null)
            ->json('conversation');

        $project = Project::query()->where('conversation_id', $conversationId)->firstOrFail();

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['conversation' => $conversationId, 'message' => 'Yes, damen.com'])
            ->assertSuccessful()
            ->assertJsonPath('ask', 'photos')
            ->assertJsonPath('gallery.batch', 1)
            ->assertJsonPath('gallery.pickUrl', route('public.projects.photos.pick', $project))
            ->assertJsonPath('gallery.suggestions.0.thumbnailUrl', 'https://93.184.216.34/gate.jpg?thumb')
            ->assertJsonPath('gallery.suggestions.0.fromWebsite', true)
            ->assertJsonPath('gallery.suggestions.0.picked', false);

        expect($project->fresh()->website)->toBe('damen.com')
            ->and($response->json('gallery.suggestions'))->toHaveCount(1);

        Http::assertSent(fn(Request $request) => $request['q'] === 'site:damen.com shipyard gate');
    });

    it('still answers when the search fails', function () {
        Http::fake(['google.serper.dev/*' => fn() => throw new RuntimeException('down')]);
        $this->project->update(['conversation_id' => null]);
        ProjectIntake::fake([
            ['reply' => 'Tick what fits.', 'description' => 'd', 'purpose' => 'e-learning', 'title' => 'Damen', 'website' => 'damen.com', 'photo_searches' => ['tug'], 'ask' => 'photos', 'done' => false],
        ]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.chat'), ['message' => 'Damen, e-learning, damen.com'])
            ->assertSuccessful()
            ->assertJsonPath('reply', 'Tick what fits.')
            ->assertJsonPath('gallery', null);
    });
});

describe('picking', function () {
    it('downloads the picked photos as uploads and marks them picked', function () {
        $png = UploadedFile::fake()->image('x.png', 900, 600)->getContent();
        Http::fake([
            '93.184.216.34/good.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/broken.jpg' => Http::response('', 404),
        ]);

        $good = PhotoSuggestion::factory()->for($this->project)->create(['image_url' => 'https://93.184.216.34/good.jpg', 'title' => 'Damen Stan Tug']);
        $broken = PhotoSuggestion::factory()->for($this->project)->create(['image_url' => 'https://93.184.216.34/broken.jpg', 'position' => 2]);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.photos.pick', $this->project), ['suggestions' => [$good->sqid, $broken->sqid]])
            ->assertSuccessful()
            ->assertJsonCount(1, 'uploads')
            ->assertJsonPath('failed', [$broken->sqid]);

        $upload = Upload::query()->firstOrFail();

        expect($response->json('uploads.0.id'))->toBe($upload->sqid)
            ->and($upload->name)->toBe('damen-stan-tug.png')
            ->and($upload->mime_type)->toBe('image/png')
            ->and($good->fresh()->isPicked())->toBeTrue()
            ->and($broken->fresh()->isPicked())->toBeFalse();
    });

    it('refuses private addresses and non-images', function () {
        Http::fake(['93.184.216.34/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);

        $private = PhotoSuggestion::factory()->for($this->project)->create(['image_url' => 'http://127.0.0.1/secret.png']);
        $html = PhotoSuggestion::factory()->for($this->project)->create(['image_url' => 'https://93.184.216.34/page', 'position' => 2]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.photos.pick', $this->project), ['suggestions' => [$private->sqid, $html->sqid]])
            ->assertSuccessful()
            ->assertJsonCount(0, 'uploads')
            ->assertJsonCount(2, 'failed');

        Http::assertNotSent(fn(Request $request) => str_contains($request->url(), '127.0.0.1'));
        expect(Upload::query()->count())->toBe(0);
    });

    it('ignores suggestions of other projects and other directors', function () {
        $foreign = PhotoSuggestion::factory()->create();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.photos.pick', $this->project), ['suggestions' => [$foreign->sqid]])
            ->assertSuccessful()
            ->assertJsonCount(0, 'uploads');

        actingAs(Director::factory()->create(), 'director')
            ->postJson(route('public.projects.photos.pick', $this->project), ['suggestions' => ['x']])
            ->assertForbidden();
    });
});
