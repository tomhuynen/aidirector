<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\ShotReviewer;
use App\Ai\Agents\StorylineWriter;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateVideo;
use App\Jobs\PollShotClips;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use App\Support\Video\ClipJoiner;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\OpenRouterVideoClient;
use App\Support\Video\StillVideo;
use Illuminate\Http\Client\Request;
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
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function stillPng(): string
{
    $image = imagecreatetruecolor(90, 160);
    imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 150));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @return list<array{title: string, description: string}>
 */
function montagePlan(): array
{
    return [
        ['title' => 'Design', 'description' => 'In a bright design office, a designer draws a hull on a large drawing table.'],
        ['title' => 'Build', 'description' => 'In the shipbuilding hall, welders join a hull section while sparks fly.'],
        ['title' => 'Repair', 'description' => 'In dry dock, a crane lowers a new propeller onto the ship.'],
    ];
}

function montageShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->montage()->create([
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'chosen_storyline' => ['storyline' => 'Designed, built and repaired.'],
        'storyline' => ['framing' => ['size' => 'full', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 7], 'keyframes' => montagePlan()],
        ...$attributes,
    ]);
}

describe('planning', function () {
    it('stores the kind the planner chose, and lets it choose again on a fresh plan', function () {
        Queue::fake();
        $answer = fn(string $kind) => ['title' => 'A Vessel For Life', 'kind' => $kind, 'storyline' => 'Designed, built and repaired.', 'framing' => ['size' => 'full', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 7], 'keyframes' => montagePlan()];
        StorylineWriter::fake([$answer('montage'), $answer('scene')]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_PENDING, 'kind' => ShotKind::SCENE]);

        (new GenerateStoryline($shot))->handle();

        // The scene it was is dropped: a fresh plan lets the planner choose.
        expect($shot->fresh()->kind)->toBe(ShotKind::MONTAGE)
            ->and((string) (new StorylineWriter(Shot::factory()->for($this->project)->make(['kind' => null])))->instructions())
            ->toContain('The kind of shot, choose one by the idea')
            ->toContain('For a scene or a medium shot:')
            ->toContain('For a montage:');

        // Keeping the kind, the planner is told which one it is and gets only its rules.
        (new GenerateStoryline($shot->fresh(), keepKind: true))->handle();

        expect($shot->fresh()->kind)->toBe(ShotKind::MONTAGE);
        StorylineWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'This shot is a montage: give montage as the kind.')
            && str_contains((string) $prompt->agent->instructions(), 'Never explain with a board, poster, screen, chart or display of pictograms')
            && str_contains((string) $prompt->agent->instructions(), 'two parts never share a place unless the story says so')
            && ! str_contains((string) $prompt->agent->instructions(), 'Keyframe 1 sets the camera for the whole shot'));
    });

    it('lets the director switch the kind in the plan chat', function () {
        Illuminate\Support\Facades\Queue::fake();
        App\Ai\Agents\PlanDirector::fake([[
            'reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'direct' => '',
            'proposal' => ['title' => '', 'kind' => 'montage', 'storyline' => 'The plan.', 'seconds' => 4, 'setting_from' => null, 'keyframes' => montagePlan()],
        ]]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'kind' => ShotKind::SCENE, 'storyline' => ['keyframes' => montagePlan()]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'make it a montage'])
            ->assertOk();

        expect($shot->fresh()->kind)->toBe(ShotKind::MONTAGE);
    });
});

describe('drawing', function () {
    it('draws every still on its own, without a keyframe 1 to choose or confirm', function () {
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => base64_encode(stillPng()));
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        Queue::fake([GenerateRemainingKeyframes::class]);
        $shot = montageShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);

        (new GenerateKeyframes($shot))->handle();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($shot->keyframes()->busy()->count())->toBe(3);
        Queue::assertPushed(GenerateRemainingKeyframes::class);

        (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class), app(KeyframeChain::class));

        $keyframes = $shot->keyframes()->with('media')->get();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->isBusy()))->toBeTrue();
        // Each still is drawn from scratch, never as an edit of another still.
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('welders join a hull section') && $prompt->contains('Visual style:'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Edit the first attached image'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'This keyframe is one still of a montage'));
    });
});

describe('video', function () {
    beforeEach(fn() => Config::set('services.luma.key', 'luma-key'));

    it('renders one Luma clip per still, pinned on that still', function () {
        Queue::fake();
        Http::fake(['agents.lumalabs.ai/v1/generations' => Http::sequence()
            ->push(['id' => 'clip_1', 'state' => 'queued'], 201)
            ->push(['id' => 'clip_2', 'state' => 'queued'], 201)
            ->push(['id' => 'clip_3', 'state' => 'queued'], 201)]);
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING]);

        foreach (montagePlan() as $index => $plan) {
            Keyframe::factory()->for($shot)->create(['position' => $index + 1, ...$plan])
                ->addMediaFromString(stillPng())->usingFileName("still-{$index}.png")->toMediaCollection(Keyframe::RENDERS);
        }

        (new GenerateVideo($shot))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));

        Http::assertSentCount(3);
        // Each still shows its share of the 7-second shot (2.67 s): the shortest clip Ray renders holds it, and the join cuts it back.
        Http::assertSent(fn(Request $request) => $request->url() === 'https://agents.lumalabs.ai/v1/generations'
            && $request['model'] === 'ray-3.2'
            && $request['video']['duration'] === '5s'
            && $request['video']['keyframe_indexes'] === [0]
            && count($request['video']['keyframes']) === 1
            && str_contains($request['prompt'], 'welders join a hull section'));
        expect($shot->fresh()->montage_clips)->toEqual([
            ['position' => 1, 'job_id' => 'clip_1', 'seconds' => 5, 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'seconds' => 5, 'status' => 'pending'],
            ['position' => 3, 'job_id' => 'clip_3', 'seconds' => 5, 'status' => 'pending'],
        ])->and($shot->fresh()->video_job_id)->toBeNull();
        Queue::assertPushed(PollShotClips::class);
    });

    it('renders each still for as long as it shows', function () {
        Queue::fake();
        Http::fake(['agents.lumalabs.ai/v1/generations' => Http::sequence()->push(['id' => 'clip_1', 'state' => 'queued'], 201)->push(['id' => 'clip_2', 'state' => 'queued'], 201)]);
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'storyline' => ['framing' => ['seconds' => 10], 'keyframes' => [
            ['title' => 'Step 1', 'description' => 'The hull is welded.', 'seconds' => 1],
            ['title' => 'Step 2', 'description' => 'The ship is launched.', 'seconds' => 4],
        ]]]);

        foreach ([1, 2] as $position) {
            Keyframe::factory()->for($shot)->create(['position' => $position])
                ->addMediaFromString(stillPng())->usingFileName("still-{$position}.png")->toMediaCollection(Keyframe::RENDERS);
        }

        (new GenerateVideo($shot))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(KeyframePainter::class), app(StillVideo::class));

        // 1 and 4 of 10 seconds: 2 s and 8 s, each with half of the one crossfade, in the shortest clips Ray renders that hold them.
        expect($shot->fresh()->keyframeSeconds())->toBe([2.0, 8.0])
            ->and(collect(Http::recorded())->map(fn(array $pair) => $pair[0]['video']['duration'])->all())->toBe(['5s', '10s'])
            ->and(array_column($shot->fresh()->montage_clips, 'seconds'))->toBe([5, 10]);
    });

    it('waits while clips render and fails the video when one fails', function () {
        Queue::fake();
        $round = 1;
        Http::fake(function () use (&$round) {
            return Http::response($round === 1 ? ['state' => 'dreaming'] : ['state' => 'failed', 'failure_reason' => 'Content moderated']);
        });
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now(), 'montage_clips' => [
            ['position' => 1, 'job_id' => 'clip_1', 'seconds' => 5, 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'seconds' => 5, 'status' => 'pending'],
        ]]);

        (new PollShotClips($shot))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);
        Queue::assertPushed(PollShotClips::class);

        $round = 2;

        (new PollShotClips($shot->fresh()))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        expect($shot->fresh())->status->toBe(ShotStatus::KEYFRAMES_READY)->montage_clips->toBeNull()->video_error->not->toBeNull()
            ->and($shot->generations()->where('error', 'Content moderated')->where('provider', 'luma')->count())->toBe(1);
    });

    it('cuts each clip to its share of the shot and joins them with crossfades', function () {
        Queue::fake();
        $directory = storage_path('tmp/montage-test');
        File::ensureDirectoryExists($directory);
        Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=4', '-pix_fmt', 'yuv420p', "{$directory}/clip.mp4"])->throw();
        $clip = File::get("{$directory}/clip.mp4");
        File::deleteDirectory($directory);
        Http::fake([
            'agents.lumalabs.ai/v1/generations/*' => Http::response(['state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://cdn.luma.test/clip.mp4']]]),
            'cdn.luma.test/*' => Http::response($clip),
        ]);
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now(), 'montage_clips' => [
            ['position' => 1, 'job_id' => 'clip_1', 'seconds' => 5, 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'seconds' => 5, 'status' => 'pending'],
            ['position' => 3, 'job_id' => 'clip_3', 'seconds' => 5, 'status' => 'pending'],
        ]]);

        foreach (montagePlan() as $index => $plan) {
            Keyframe::factory()->for($shot)->create(['position' => $index + 1, ...$plan]);
        }

        (new PollShotClips($shot))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        $shot->refresh();
        $video = $shot->getFirstMedia(Shot::VIDEO);

        // Seven seconds over three stills: each shows about 2.67 s, the crossfades overlapping by half a second; three 5-second clips at 540p from the price list.
        expect($shot->status)->toBe(ShotStatus::VIDEO_READY)
            ->and($shot->montage_clips)->toBeNull()
            ->and($shot->getMedia(Shot::MONTAGE_CLIPS))->toBeEmpty()
            ->and($video)->not->toBeNull()
            ->and($video->getCustomProperty(Shot::VIDEO_SECONDS))->toEqualWithDelta(7.0, 0.1)
            ->and($shot->generations()->where('kind', 'video')->firstOrFail())->provider->toBe('luma')->cost->toEqualWithDelta(0.45, 0.001);
    })->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

    it('cuts each clip to how long its still shows, with its share of the crossfades', function () {
        expect(PollShotClips::clipLengths([7 / 3, 7 / 3, 7 / 3]))->toBe([2.67, 2.67, 2.67])
            ->and(PollShotClips::clipLengths(array_fill(0, 6, 4 / 6)))->toBe(array_fill(0, 6, 1.5))
            ->and(PollShotClips::clipLengths([1.5, 4.0]))->toBe([1.75, 4.25]);
    });
});
