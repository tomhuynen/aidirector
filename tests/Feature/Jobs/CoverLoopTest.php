<?php

declare(strict_types=1);

use App\Ai\ProjectCoverPainter;
use App\Enums\Disk;
use App\Jobs\GenerateCoverLoop;
use App\Jobs\GenerateProjectCover;
use App\Jobs\PollCoverLoop;
use App\Models\Director;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function loopMp4(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
}

function withCover(Project $project): Project
{
    $project->addMediaFromString(UploadedFile::fake()->image('cover.png', 400, 100)->getContent())
        ->usingFileName('cover.png')
        ->toMediaCollection(Project::COVER);

    return $project;
}

it('starts the loop in the background once the cover is drawn, and drops the old loop', function () {
    Queue::fake();
    $this->project->addMediaFromString(loopMp4())->usingFileName('old.mp4')->toMediaCollection(Project::COVER_LOOP);

    $painter = Mockery::mock(ProjectCoverPainter::class);
    $painter->shouldReceive('canPaint')->andReturnTrue();
    $painter->shouldReceive('paint')->andReturnUsing(fn(Project $project) => withCover($project));

    (new GenerateProjectCover($this->project))->handle($painter);

    expect($this->project->fresh()->getFirstMedia(Project::COVER_LOOP))->toBeNull();
    Queue::assertPushed(GenerateCoverLoop::class, fn(GenerateCoverLoop $job) => $job->project->is($this->project));
});

it('asks for a loop that starts and ends on the cover, padded to the widest ratio', function () {
    Queue::fake();
    Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'loop_1', 'status' => 'pending'])]);
    withCover($this->project);

    (new GenerateCoverLoop($this->project))->handle(app(App\Support\Video\OpenRouterVideoClient::class));

    Http::assertSent(fn(Request $request) => $request['model'] === config('pipeline.models.cover_loop')
        && $request['aspect_ratio'] === '21:9'
        && $request['frame_images'][0]['frame_type'] === 'first_frame'
        && $request['frame_images'][1]['frame_type'] === 'last_frame'
        && $request['frame_images'][0]['image_url']['url'] === $request['frame_images'][1]['image_url']['url']
        && str_contains($request['prompt'], 'seamless loop')
        && str_contains($request['prompt'], 'The people talk to each other')
        && $request['generate_audio'] === false);

    Queue::assertPushed(PollCoverLoop::class, fn(PollCoverLoop $job) => $job->jobId === 'loop_1'
        && $job->coverId === $this->project->getFirstMedia(Project::COVER)->id);
});

it('pads the 4:1 cover to 21:9 with the cover in the middle', function () {
    $frame = imagecreatefromstring(GenerateCoverLoop::paddedFrame(UploadedFile::fake()->image('cover.png', 420, 105)->getContent()));

    expect(imagesx($frame))->toBe(420)->and(imagesy($frame))->toBe(180);
});

it('keeps the finished loop on the project and shows it in the header', function () {
    Http::fake([
        'openrouter.ai/api/v1/videos/loop_1' => Http::response(['id' => 'loop_1', 'status' => 'completed', 'usage' => ['cost' => 0.4]]),
        'openrouter.ai/api/v1/videos/loop_1/content*' => Http::response(loopMp4(), 200, ['Content-Type' => 'video/mp4']),
    ]);
    withCover($this->project);

    (new PollCoverLoop($this->project, 'loop_1', $this->project->getFirstMedia(Project::COVER)->id, now()->getTimestamp()))
        ->handle(app(App\Support\Video\OpenRouterVideoClient::class));

    expect($this->project->fresh()->getFirstMedia(Project::COVER_LOOP))->not->toBeNull()
        ->and($this->project->generations()->where('kind', 'video')->first()->usage)->toBe(['cost' => 0.4]);

    actingAs($this->director, 'director')
        ->get(route('public.projects.view', $this->project))
        ->assertInertia(fn($page) => $page->whereNot('project.coverLoopUrl', null));
});

it('drops a loop made for a cover that was drawn again in the meantime', function () {
    Http::fake();
    withCover($this->project);

    (new PollCoverLoop($this->project, 'loop_1', 999999, now()->getTimestamp()))
        ->handle(app(App\Support\Video\OpenRouterVideoClient::class));

    Http::assertNothingSent();
    expect($this->project->getFirstMedia(Project::COVER_LOOP))->toBeNull();
});
