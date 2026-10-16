<?php

declare(strict_types=1);

use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\KeyframeStatus;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateVideo;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\StillVideo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function timedShot(Project $project, ShotKind $kind, int $count, int $duration = 6): Shot
{
    $shot = Shot::factory()->for($project)->create(['kind' => $kind, 'status' => ShotStatus::KEYFRAMES_READY, 'duration' => $duration]);

    foreach (range(1, $count) as $position) {
        $image = imagecreatetruecolor(9, 16);
        ob_start();
        imagepng($image);

        Keyframe::factory()->for($shot)->create(['position' => $position, 'title' => "Step {$position}", 'status' => KeyframeStatus::READY])
            ->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
    }

    return $shot;
}

function setSeconds(object $test, Shot $shot, int $position, float $seconds)
{
    return actingAs($test->director, 'director')
        ->post(route('public.shots.keyframes.seconds', [$test->project, $shot, $shot->keyframes()->where('position', $position)->firstOrFail()]), ['seconds' => $seconds]);
}

it('times the keyframes of a scene by the planner\'s seconds, as shares of the shot length', function () {
    $shot = timedShot($this->project, ShotKind::SCENE, 3, 6);
    $shot->forceFill(['storyline' => ['framing' => ['seconds' => 6], 'keyframes' => [
        ['title' => 'Step 1', 'description' => 'She stands.', 'seconds' => 0.5],
        ['title' => 'Step 2', 'description' => 'She turns.', 'seconds' => 2],
        ['title' => 'Step 3', 'description' => 'She walks out.', 'seconds' => 0.5],
    ]], 'duration' => null])->save();

    // Planned 3 seconds in all, the shot is 6: every keyframe takes twice its planned time.
    expect($shot->fresh()->keyframeSeconds())->toBe([1.0, 4.0, 1.0])
        ->and(GenerateVideo::timeline($shot->fresh(), $shot->keyframes()->get()))->toBe([
            ['position' => 1, 'title' => 'Step 1', 'seconds' => 0.0],
            ['position' => 2, 'title' => 'Step 2', 'seconds' => 1.0],
            ['position' => 3, 'title' => 'Step 3', 'seconds' => 5.0],
        ]);

    setSeconds($this, $shot, 2, 3)->assertSessionHasErrors('seconds');

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('keyframes.0.links.seconds', null)->where('shot.keyframeSeconds', null));
});

it('times one keyframe of a close-up by hand, keeps the others as they were and makes the shot as long as they are together', function () {
    $shot = timedShot($this->project, ShotKind::CLOSE_UP, 3);

    setSeconds($this, $shot, 2, 3.5)->assertSessionHasNoErrors();

    expect($shot->fresh()->keyframeSeconds())->toBe([2.0, 3.5, 2.0])
        ->and($shot->fresh()->duration)->toBe(8)
        ->and(GenerateVideo::timeline($shot->fresh(), $shot->keyframes()->get()))->toBe([
            ['position' => 1, 'title' => 'Step 1', 'seconds' => 0.0],
            ['position' => 2, 'title' => 'Step 2', 'seconds' => 2.0],
            ['position' => 3, 'title' => 'Step 3', 'seconds' => 5.5],
        ]);
    Queue::assertNotPushed(GenerateVideo::class);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.keyframeSeconds', [2, 3.5, 2])->where('shot.seconds', 8)->where('keyframes.0.links.seconds', fn(?string $url) => $url !== null));
});

it('shortens the shot when a timed keyframe is deleted', function () {
    $shot = timedShot($this->project, ShotKind::CLOSE_UP, 3);
    setSeconds($this, $shot, 1, 4);

    actingAs($this->director, 'director')
        ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $shot->keyframes()->where('position', 1)->firstOrFail()]))
        ->assertSessionHasNoErrors();

    expect($shot->fresh()->keyframeSeconds())->toBe([2.0, 2.0])
        ->and($shot->fresh()->duration)->toBe(4);
});

it('keeps the shot within what the video model renders, and refuses a presenter or a shot whose video is being made', function () {
    $shot = timedShot($this->project, ShotKind::CLOSE_UP, 3);
    $presenter = timedShot($this->project, ShotKind::PRESENTER, 1);

    setSeconds($this, $shot, 1, 12)->assertSessionHasErrors('seconds');
    setSeconds($this, $shot, 1, 1.25)->assertSessionHasErrors('seconds');
    setSeconds($this, $presenter, 1, 3)->assertSessionHasErrors('seconds');

    $shot->forceFill(['status' => ShotStatus::VIDEO_PENDING])->save();
    setSeconds($this, $shot, 1, 3)->assertSessionHasErrors('seconds');

    expect($shot->keyframes()->whereNotNull('seconds')->exists())->toBeFalse()
        ->and($shot->fresh()->duration)->toBe(6);
});

it('makes an insert\'s clip again at once, at its keyframe\'s length', function () {
    $shot = timedShot($this->project, ShotKind::INSERT, 1, 3);
    $shot->forceFill(['status' => ShotStatus::VIDEO_READY])->save();
    $directory = storage_path('tmp/seconds-test');
    File::ensureDirectoryExists($directory);
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=1', '-pix_fmt', 'yuv420p', "{$directory}/clip.mp4"])->throw();
    $shot->addMedia("{$directory}/clip.mp4")->toMediaCollection(Shot::VIDEO);
    File::deleteDirectory($directory);

    setSeconds($this, $shot, 1, 2.5)->assertSessionHasNoErrors();

    expect($shot->fresh()->keyframeSeconds())->toBe([2.5])
        ->and($shot->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING)
        ->and($shot->fresh()->video())->toBeNull();
    Queue::assertPushed(GenerateVideo::class);
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

it('gives the video model the times of a close-up the director timed, and none for a scene', function () {
    App\Ai\Agents\VideoPromptWriter::fake(fn() => ['style' => 'Soft 3D.', 'action' => 'She clips the badge on.', 'details' => '']);
    Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'vid_1'])]);
    $closeUp = timedShot($this->project, ShotKind::CLOSE_UP, 2, 4);
    $scene = timedShot($this->project, ShotKind::SCENE, 2, 4);
    setSeconds($this, $closeUp, 1, 1.5);

    (new GenerateVideo($closeUp->fresh()))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));
    (new GenerateVideo($scene->fresh()))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));

    expect($closeUp->fresh()->video_prompt)->toContain('Timing: Keyframe 1 (Step 1) at about 0.0 s. Keyframe 2 (Step 2) at about 1.5 s.')
        ->and($scene->fresh()->video_prompt)->toContain('Pace it naturally within the 4 seconds')->not->toContain('Timing:');
});

it('renders each still of a montage for as long as it shows', function () {
    Http::fake(['openrouter.ai/api/v1/videos' => Http::sequence()->push(['id' => 'clip_1'])->push(['id' => 'clip_2'])]);
    $shot = timedShot($this->project, ShotKind::MONTAGE, 2, 6);
    $shot->forceFill(['duration' => null, 'storyline' => ['framing' => ['seconds' => 6], 'keyframes' => [
        ['title' => 'Step 1', 'description' => 'The hull is welded.', 'seconds' => 1.5],
        ['title' => 'Step 2', 'description' => 'The ship is launched.', 'seconds' => 4],
    ]]])->save();

    (new GenerateVideo($shot->fresh()))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));

    // 1.5 and 4 of 6 seconds: about 1.6 s and 4.4 s, each with half of the one crossfade, rendered as 2 s and 5 s and cut back when joined.
    $durations = collect(Http::recorded())->map(fn(array $pair) => $pair[0]['duration'])->all();
    expect($durations)->toBe([2, 5]);
    Http::assertSent(fn(Request $request) => $request['duration'] === 5);
});
