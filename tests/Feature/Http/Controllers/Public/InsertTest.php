<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\KeyframePainter;
use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Controllers\Public\Shots\Keyframes\StoreController;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateVideo;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\StillVideo;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT]);
});

function insertPng(): string
{
    $image = imagecreatetruecolor(90, 160);
    imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 225));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @return list<array{title: string, description: string, elements: list<string>}>
 */
function insertPlan(): array
{
    return [['title' => 'No-camera sign', 'description' => 'The No-camera pictogram sign on the plain dockside wall, the quay soft behind it.', 'elements' => ['No-camera pictogram sign', 'Quayside Dock Basin']]];
}

function insertShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'kind' => ShotKind::INSERT,
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'duration' => 3,
        'chosen_storyline' => ['storyline' => 'The no-camera sign hangs on the dockside wall.'],
        'storyline' => ['framing' => ['size' => 'insert', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 3], 'keyframes' => insertPlan()],
        ...$attributes,
    ]);
}

it('plans one still of the thing alone with the insert rules', function () {
    Queue::fake();
    StorylineWriter::fake([['title' => 'No-camera sign', 'kind' => 'insert', 'storyline' => 'The no-camera sign hangs on the dockside wall.', 'framing' => ['size' => 'insert', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 3], 'keyframes' => insertPlan()]]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_PENDING, 'kind' => ShotKind::INSERT]);

    (new GenerateStoryline($shot, keepKind: true))->handle();

    expect($shot->fresh()->kind)->toBe(ShotKind::INSERT)
        ->and(collect(ShotKind::catalogue())->pluck('value'))->toContain('insert');
    StorylineWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Exactly one keyframe: the thing on its own')
        && str_contains((string) $prompt->agent->instructions(), 'No people and no hands')
        && ! str_contains((string) $prompt->agent->instructions(), 'Never explain with a board'));
});

it('draws the still straight away, the thing alone in the middle, and checks it as an insert', function () {
    Config::set('pipeline.keyframe_check', true);
    Image::fake(fn() => base64_encode(insertPng()));
    KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
    Queue::fake([GenerateRemainingKeyframes::class]);
    $shot = insertShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);
    $sign = Element::factory()->for($this->project)->create(['name' => 'No-camera pictogram sign', 'type' => ElementType::OBJECT]);
    $sign->addMediaFromString(insertPng())->usingFileName('sign.png')->toMediaCollection(Element::REFERENCE);

    (new GenerateKeyframes($shot))->handle();
    (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

    expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
        ->and($shot->keyframes()->with('media')->first()->render())->not->toBeNull()
        ->and(StoreController::canAddTo($shot->fresh(), 1, true, false))->toBeFalse();
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Insert: the one thing fills about half of the frame')
        && $prompt->contains('No people, no hands and no other objects in front of it')
        && ! $prompt->contains('Staging: the people are inside the place'));
    KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'This keyframe is an insert'));
});

it('offers no keyframe to add to an insert or a presenter, and does to a scene', function (ShotKind $kind, bool $one) {
    $shot = Shot::factory()->for($this->project)->create(['kind' => $kind, 'status' => ShotStatus::KEYFRAMES_READY]);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.oneKeyframe', $one));
})->with([
    'insert' => [ShotKind::INSERT, true],
    'presenter' => [ShotKind::PRESENTER, true],
    'scene' => [ShotKind::SCENE, false],
]);

it('makes the clip from the still in code, without the video model', function () {
    Queue::fake();
    Http::fake();
    $shot = insertShot($this->project, ['status' => ShotStatus::VIDEO_PENDING]);
    $still = Keyframe::factory()->for($shot)->create(['position' => 1]);
    $still->addMediaFromString(insertPng())->usingFileName('still.png')->toMediaCollection(Keyframe::RENDERS);

    (new GenerateVideo($shot))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));

    $video = $shot->fresh()->getFirstMedia(Shot::VIDEO);
    $directory = storage_path('tmp/insert-test');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/shot.mp4", (string) Storage::disk($video->disk)->get($video->getPathRelativeToRoot()));
    $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=width,height', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1', "{$directory}/shot.mp4"])->output();
    File::deleteDirectory($directory);

    expect($shot->fresh()->status)->toBe(ShotStatus::VIDEO_READY)
        ->and($probe)->toContain('width=480')->toContain('height=854')->toContain('duration=3.0')
        ->and($shot->generations()->where('kind', 'video')->first()->provider)->toBe('ffmpeg')
        ->and($shot->fresh()->video_summary)->toEndWith('The camera pushes in slowly.');

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.videoSent.model', 'ffmpeg')->where('shot.videoSent.summary', $shot->fresh()->video_summary));
    Http::assertNothingSent();
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');
