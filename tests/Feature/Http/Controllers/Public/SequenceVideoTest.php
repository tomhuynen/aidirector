<?php

declare(strict_types=1);

use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\KeyframeStatus;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\MergeShotVideos;
use App\Jobs\PollSequenceRepair;
use App\Jobs\PollSequenceVideo;
use App\Jobs\PollVideos;
use App\Jobs\RenderSequenceVideo;
use App\Jobs\RepairSequenceShot;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Images\ReplicateClient;
use App\Support\Video\ClipSplicer;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\SceneCuts;
use App\Support\Video\SilentVideo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Config::set('pipeline.models.sequence_video', 'kwaivgi/kling-v3-omni-video');
    Config::set('services.replicate.token', 'replicate-key');

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A shot of the sequence "kling", planned with a start frame and an end frame.
 *
 * @param  array<string, mixed>  $attributes
 */
function klingShot(Project $project, int $position, ShotKind $kind = ShotKind::SCENE, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'position' => $position,
        'kind' => $kind,
        'group_key' => 'kling',
        'title' => "Shot {$position}",
        'idea' => "The visitor does act {$position}.",
        'status' => ShotStatus::STORYLINE_READY,
        'storyline' => ['framing' => ['spot' => '', 'seconds' => 3], 'keyframes' => [
            ['title' => 'Start', 'description' => 'She walks to the bag.', 'spatial' => ''],
            ['title' => 'End', 'description' => 'She stands by the bag.', 'spatial' => ''],
        ]],
        ...$attributes,
    ]);
}

function klingPng(): string
{
    $image = imagecreatetruecolor(9, 16);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Gives a shot its one drawn start frame, so it is ready to render with its sequence.
 */
function klingStartFrame(Shot $shot, ?Element $person = null): Shot
{
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1]);
    $render = $keyframe->addMediaFromString(klingPng())->usingFileName("start-{$shot->position}.png")->toMediaCollection(Keyframe::RENDERS);
    $keyframe->forceFill(['render_id' => $render->id, 'status' => KeyframeStatus::READY])->save();

    if ($person !== null) {
        $keyframe->elements()->sync([$person->id]);
    }

    $shot->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

    return $shot;
}

it('draws one start frame per shot of a sequence in the place of the master plate, with options to choose from', function () {
    $first = klingShot($this->project, 1);
    $second = klingShot($this->project, 2, ShotKind::CLOSE_UP);
    $montage = klingShot($this->project, 3, ShotKind::MONTAGE);
    Bus::fake();

    (new GenerateKeyframes($second))->handle();

    expect($first->settingFrom())->toBeNull()
        ->and($second->settingFrom()['shot']->is($first))->toBeTrue()
        ->and($second->settingFrom()['keyframe'])->toBe(1)
        ->and($second->continuesPose())->toBeFalse()
        ->and($second->choosesStartFrame())->toBeTrue()
        ->and($first->continuedBy())->toBeEmpty()
        ->and($montage->rendersInSequence())->toBeFalse()
        ->and($second->keyframes()->pluck('title')->all())->toBe(['Start']);
    Bus::assertBatched(fn($batch) => $batch->jobs->count() === GenerateKeyframes::optionCount());
});

it('takes only the place from the master plate, never the poses of its start frame', function () {
    $first = klingStartFrame(klingShot($this->project, 1));
    $second = klingShot($this->project, 2, ShotKind::MEDIUM);
    $keyframe = Keyframe::factory()->for($second)->create(['position' => 1, 'description' => 'She looks at the sign.']);

    $references = app(KeyframePainter::class)->referencesFor($keyframe, collect([$keyframe]));

    expect($references->setting)->toBeNull()
        ->and($references->settingIsMoment)->toBeFalse()
        ->and($references->master)->not->toBeNull()
        ->and($references->masterLabel)->toBe("the start frame of {$first->code()}, the first shot of this sequence");
});

it('waits for the master plate, and is ready as soon as its one start frame is chosen', function () {
    $first = klingShot($this->project, 1);
    $second = klingShot($this->project, 2);
    Queue::fake();

    $second->drawKeyframes();

    expect($second->fresh()->waitsToDraw())->toBeTrue()
        ->and($second->settingWaitsFor())->toBe('keyframe 1 of SH010');

    klingStartFrame($first);
    $first->forceFill(['status' => ShotStatus::KEYFRAMES_PENDING])->save();
    (new GenerateRemainingKeyframes($first))->handle(app(App\Ai\KeyframePainter::class), app(App\Support\Shots\KeyframeChain::class));

    expect($first->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
        ->and($second->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
    Queue::assertPushed(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->shot->is($second));
});

it('renders a sequence once every shot has its start frame, without a video per shot', function () {
    Queue::fake();
    $shots = collect([1, 2, 3])->map(fn(int $position) => klingStartFrame(klingShot($this->project, $position)));
    $missing = klingShot($this->project, 4);

    actingAs($this->director, 'director')
        ->post(route('public.shots.merge', $this->project), ['shots' => [...$shots->pluck('sqid'), $missing->sqid], 'title' => 'The bag', 'transition' => 'cut'])
        ->assertSessionHasErrors(['shots' => 'Every shot needs its start frame before the sequence can be rendered.']);

    actingAs($this->director, 'director')
        ->post(route('public.shots.merge', $this->project), ['shots' => $shots->pluck('sqid')->all(), 'title' => 'The bag', 'transition' => 'cut'])
        ->assertSessionHasNoErrors();

    $merged = $this->project->shots()->where('title', 'The bag')->sole();

    expect($merged->status)->toBe(ShotStatus::VIDEO_PENDING);
    Queue::assertPushed(RenderSequenceVideo::class, fn(RenderSequenceVideo $job) => $job->shot->is($merged));
    Queue::assertNotPushed(MergeShotVideos::class);

    actingAs($this->director, 'director')
        ->post(route('public.shots.video.generate', [$this->project, $missing]))
        ->assertSessionHasErrors(['video' => "Choose the start frame of {$missing->fresh()->code()} first: the sequence renders from the start frame of every shot."]);
});

it('renders the whole sequence from the video button of any of its shots', function () {
    Queue::fake();
    $shots = collect([1, 2, 3])->map(fn(int $position) => klingStartFrame(klingShot($this->project, $position)));
    $choosing = $shots[2]->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_READY]);
    $choosing->save();

    actingAs($this->director, 'director')
        ->post(route('public.shots.video.generate', [$this->project, $shots[1]]))
        ->assertSessionHasErrors(['video' => "Choose the start frame of {$choosing->code()} first: the sequence renders from the start frame of every shot."]);

    $choosing->forceFill(['status' => ShotStatus::KEYFRAMES_READY])->save();

    $response = actingAs($this->director, 'director')
        ->post(route('public.shots.video.generate', [$this->project, $shots[1]]))
        ->assertSessionHasNoErrors();

    $merged = $this->project->shots()->whereNotNull('merge_transition')->sole();

    $response->assertRedirect(route('public.shots.view', [$this->project, $merged]));
    expect($merged->parts()->pluck('id')->all())->toBe($shots->pluck('id')->all())
        ->and($merged->status)->toBe(ShotStatus::VIDEO_PENDING);
    Queue::assertPushed(RenderSequenceVideo::class, fn(RenderSequenceVideo $job) => $job->shot->is($merged));
});

it('sends the first start frame as the start, the other start frames and the people as references, and lets the model time the cuts', function () {
    Queue::fake();
    $person = Element::factory()->for($this->project)->create(['name' => 'Helmeted visitor']);
    $person->addMediaFromString(klingPng())->usingFileName('visitor.png')->toMediaCollection(Element::REFERENCE);
    $shots = collect([1, 2, 3])->map(fn(int $position) => klingStartFrame(klingShot($this->project, $position, $position === 3 ? ShotKind::CLOSE_UP : ShotKind::SCENE), $person));
    $merged = Shot::factory()->for($this->project)->create(['position' => 1, 'merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_PENDING]);
    Shot::query()->whereKey($shots->pluck('id'))->update(['merged_into_id' => $merged->id]);
    $uploads = 0;
    Http::fake([
        'api.replicate.com/v1/files' => function () use (&$uploads) {
            $uploads++;

            return Http::response(['urls' => ['get' => "https://api.replicate.com/v1/files/f{$uploads}"]]);
        },
        'api.replicate.com/v1/models/*' => Http::response(['id' => 'pred-1', 'status' => 'starting']),
    ]);

    (new RenderSequenceVideo($merged))->handle(app(ReplicateClient::class));

    expect($merged->fresh())->video_job_id->toBe('pred-1')
        ->video_prompt->toContain('Helmeted visitor is <<<image_3>>>: exactly this look in every shot.')
        ->video_prompt->toContain('Shot 1: wide shot, fixed camera, starting on the first frame: The visitor does act 1.')
        ->video_prompt->toContain('Shot 2: wide shot, fixed camera framed like <<<image_1>>>: The visitor does act 2.')
        ->video_prompt->toContain('Shot 3: close-up on the hands and the object framed like <<<image_2>>>: The visitor does act 3.');
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'models/kwaivgi/kling-v3-omni-video/predictions')
        && $request['input']['start_image'] === 'https://api.replicate.com/v1/files/f4'
        && $request['input']['reference_images'] === ['https://api.replicate.com/v1/files/f1', 'https://api.replicate.com/v1/files/f2', 'https://api.replicate.com/v1/files/f3']
        && $request['input']['duration'] === 9
        && $request['input']['generate_audio'] === false
        && ! isset($request['input']['multi_prompt'])
        && ! isset($request['input']['end_image']));
    Queue::assertPushed(PollSequenceVideo::class, fn(PollSequenceVideo $job) => $job->jobId === 'pred-1');
});

it('checks again while the clip renders, and stores it with where each part starts', function () {
    Queue::fake();
    $shots = collect([1, 2])->map(fn(int $position) => klingShot($this->project, $position, attributes: ['status' => ShotStatus::KEYFRAMES_READY, 'voice_over' => "Line {$position}."]));
    $merged = Shot::factory()->for($this->project)->create(['position' => 1, 'merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_PENDING, 'video_job_id' => 'pred-1', 'video_submitted_at' => now()]);
    Shot::query()->whereKey($shots->pluck('id'))->update(['merged_into_id' => $merged->id]);
    Http::fakeSequence('api.replicate.com/v1/predictions/pred-1')
        ->push(['status' => 'processing'])
        ->push(['status' => 'succeeded', 'output' => 'https://replicate.delivery/clip.mp4']);
    Http::fake(['replicate.delivery/*' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32))]);
    $cuts = Mockery::mock(SceneCuts::class);
    $cuts->shouldReceive('read')->once()->andReturn(['starts' => [0.0, 2.4], 'seconds' => 5.0]);
    $silent = Mockery::mock(SilentVideo::class);
    $silent->shouldReceive('strip')->andReturnArg(0);

    (new PollSequenceVideo($merged, 'pred-1'))->handle(app(ReplicateClient::class), $cuts, $silent);

    expect($merged->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);
    Queue::assertPushed(PollSequenceVideo::class);

    (new PollSequenceVideo($merged, 'pred-1'))->handle(app(ReplicateClient::class), $cuts, $silent);

    $merged->refresh();

    expect($merged->status)->toBe(ShotStatus::VIDEO_READY)
        ->and($merged->voice_over)->toBe('Line 1. Line 2.')
        ->and($merged->video()->getCustomProperty(Shot::PART_STARTS))->toEqual([0, 2.4])
        ->and($merged->video()->getCustomProperty(Shot::VIDEO_SECONDS))->toEqual(5)
        ->and($merged->generations()->where('kind', 'video')->sole()->provider)->toBe('replicate');
});

it('leaves a sequence that renders as one clip to its own poll, not to the Luma poll', function () {
    Queue::fake();
    Http::fake();
    Shot::factory()->for($this->project)->create(['merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_PENDING, 'video_job_id' => 'pred-1', 'video_submitted_at' => now()->subHour()]);

    (new PollVideos())->handle(app(LumaVideoClient::class));

    Http::assertNothingSent();
});

it('finds the cut between the shots of a clip', function () {
    $clip = storage_path('framework/testing/sequence.mp4');
    @mkdir(dirname($clip), 0777, true);
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=2:r=24[a];color=c=red:s=64x64:d=3:r=24[b];[a][b]concat=n=2:v=1', '-pix_fmt', 'yuv420p', $clip])->throw();

    expect(app(SceneCuts::class)->read($clip, 2))->toBe(['starts' => [0.0, 2.0], 'seconds' => 5.0]);
    @unlink($clip);
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

/**
 * A sequence of two parts rendered in one clip, the second part starting 2.4 seconds in.
 */
function renderedKlingSequence(Project $project): Shot
{
    $parts = collect([1, 2])->map(fn(int $position) => klingStartFrame(klingShot($project, $position)));
    $merged = Shot::factory()->for($project)->create(['position' => 1, 'merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_READY]);
    Shot::query()->whereKey($parts->pluck('id'))->update(['merged_into_id' => $merged->id]);
    $merged->addMediaFromString("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32))
        ->usingFileName('shot-1.mp4')
        ->withCustomProperties([Shot::PART_STARTS => [0.0, 2.4], Shot::VIDEO_SECONDS => 5.0])
        ->toMediaCollection(Shot::VIDEO);

    return $merged->fresh();
}

it('offers to render each part of a sequence clip again, and starts it with what must be right', function () {
    Queue::fake();
    $merged = renderedKlingSequence($this->project);
    $second = $merged->parts()->get()->last();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $merged]))
        ->assertInertia(fn($page) => $page->where('merge.sequence', true)
            ->where('merge.parts.1.start', 2.4)
            ->where('merge.parts.1.repairUrl', route('public.shots.merge.repair', [$this->project, $merged, $second])));

    actingAs($this->director, 'director')
        ->post(route('public.shots.merge.repair', [$this->project, $merged, $second]), ['instruction' => 'Her hand never touches the handle.'])
        ->assertSessionHasNoErrors();

    expect($merged->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);
    Queue::assertPushed(RepairSequenceShot::class, fn(RepairSequenceShot $job) => $job->part->is($second) && $job->instruction === 'Her hand never touches the handle.');

    actingAs($this->director, 'director')
        ->post(route('public.shots.merge.repair', [$this->project, $merged, $second]))
        ->assertSessionHasErrors(['instruction' => 'Wait until the sequence has its video.']);
});

it('renders a part again on Luma between its own first and last frame, in the time the sequence gave it', function () {
    Queue::fake();
    Config::set('services.luma.key', 'luma-key');
    $merged = renderedKlingSequence($this->project);
    $second = $merged->parts()->get()->last();
    $splicer = Mockery::mock(ClipSplicer::class);
    $splicer->shouldReceive('frameAt')->twice()->andReturn(klingPng());
    Http::fake(['agents.lumalabs.ai/v1/generations' => Http::response(['id' => 'gen-9', 'state' => 'queued'], 201)]);

    (new RepairSequenceShot($merged, $second, 'Her hand never touches the handle.'))->handle(app(LumaVideoClient::class), $splicer);

    // Part 2 runs from 2.4 s to the end at 5 s: 62 frames, pinned there and again on the last frame of the 5-second clip.
    Http::assertSent(fn(Request $request) => $request['video']['keyframe_indexes'] === [0, 62, 120]
        && $request['video']['duration'] === '5s'
        && str_contains($request['prompt'], 'Action: The visitor does act 2.')
        && str_contains($request['prompt'], 'This must be right: Her hand never touches the handle.'));
    expect($merged->fresh()->video_job_id)->toBe('gen-9');
    Queue::assertPushed(PollSequenceRepair::class, fn(PollSequenceRepair $job) => $job->start === 2.4 && $job->end === 5.0 && $job->clipSeconds === 5);
});

it('puts the part rendered again in place, and keeps where each part starts', function () {
    Queue::fake();
    Config::set('services.luma.key', 'luma-key');
    $merged = renderedKlingSequence($this->project);
    $merged->forceFill(['status' => ShotStatus::VIDEO_PENDING, 'video_job_id' => 'gen-9', 'video_submitted_at' => now()])->save();
    Http::fake([
        'agents.lumalabs.ai/v1/generations/gen-9' => Http::response(['id' => 'gen-9', 'state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://cdn.luma.test/piece.mp4']]]),
        'cdn.luma.test/*' => Http::response('piece'),
    ]);
    $splicer = Mockery::mock(ClipSplicer::class);
    $splicer->shouldReceive('splice')->once()->withArgs(fn(string $clip, string $piece, float $start, float $end, string $output) => $start === 2.4 && $end === 5.0 && file_put_contents($output, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x01", 32)) > 0);

    (new PollSequenceRepair($merged, 'gen-9', 2.4, 5.0, 'The prompt', 5))->handle(app(LumaVideoClient::class), $splicer);

    $merged->refresh();

    expect($merged->status)->toBe(ShotStatus::VIDEO_READY)
        ->and($merged->video()->getCustomProperty(Shot::PART_STARTS))->toEqual([0, 2.4])
        ->and(Storage::disk(Disk::TENANT->value)->get($merged->video()->getPathRelativeToRoot()))->toContain("\x01")
        ->and($merged->generations()->where('kind', 'video')->sole()->cost)->toEqual(0.30);
});

it('puts a new piece into a clip in place of the part between two moments', function () {
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=5:r=24', '-pix_fmt', 'yuv420p', "{$dir}/clip.mp4"])->throw();
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=32x32:d=5:r=24', '-pix_fmt', 'yuv420p', "{$dir}/piece.mp4"])->throw();
    $splicer = app(ClipSplicer::class);

    $splicer->splice("{$dir}/clip.mp4", "{$dir}/piece.mp4", 2.0, 4.0, "{$dir}/out.mp4");
    $red = imagecreatefromstring($splicer->frameAt("{$dir}/out.mp4", 3.0));
    $blue = imagecreatefromstring($splicer->frameAt("{$dir}/out.mp4", 4.5));
    $seconds = (float) Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', "{$dir}/out.mp4"])->output();

    expect($seconds)->toEqualWithDelta(5.0, 0.1)
        ->and(imagecolorat($red, 10, 10) >> 16 & 0xFF)->toBeGreaterThan(200)
        ->and(imagecolorat($blue, 10, 10) & 0xFF)->toBeGreaterThan(200)
        ->and(imagesx($red))->toBe(64);
    @unlink("{$dir}/clip.mp4");
    @unlink("{$dir}/piece.mp4");
    @unlink("{$dir}/out.mp4");
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');
