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
use App\Support\Video\ClipJoiner;
use App\Support\Video\OpenRouterVideoClient;
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
        'chosen_storyline' => ['title' => 'A Vessel For Life', 'storyline' => 'Designed, built and repaired.'],
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
            ->toContain('The kind of shot, choose one by the takeaway')
            ->toContain('For a scene:')
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
            'reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [],
            'proposal' => ['takeaway' => '', 'kind' => 'montage', 'storyline' => 'The plan.', 'seconds' => 4, 'setting_from' => null, 'keyframes' => montagePlan()],
        ]]);
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'kind' => ShotKind::SCENE, 'storyline' => ['keyframes' => montagePlan()]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'make it a montage'])
            ->assertOk();

        expect($shot->fresh()->kind)->toBe(ShotKind::MONTAGE);
    });
});

describe('drawing', function () {
    it('draws every still on its own, without a place to choose or a keyframe 1 to confirm', function () {
        Config::set('pipeline.keyframes.start_with_plate', true);
        Config::set('pipeline.keyframe_check', true);
        Image::fake(fn() => base64_encode(stillPng()));
        KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
        ShotReviewer::fake(fn() => ['clear' => true, 'notes' => []]);
        Queue::fake([GenerateRemainingKeyframes::class]);
        $shot = montageShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);

        (new GenerateKeyframes($shot))->handle();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_PENDING)
            ->and($shot->fresh()->getMedia(Shot::PLATE_OPTIONS))->toBeEmpty()
            ->and($shot->keyframes()->where('rendering', true)->count())->toBe(3);
        Queue::assertPushed(GenerateRemainingKeyframes::class);

        (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class));

        $keyframes = $shot->keyframes()->with('media')->get();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($keyframes->every(fn(Keyframe $keyframe) => $keyframe->render() !== null && ! $keyframe->rendering))->toBeTrue();
        // Each still is drawn from scratch, never as an edit of another still.
        Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('welders join a hull section') && $prompt->contains('Visual style:'));
        Image::assertNotGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Edit the first attached image'));
        KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'This keyframe is one still of a montage'));
    });
});

describe('video', function () {
    it('renders one clip per still, starting on that still', function () {
        Queue::fake();
        Http::fake(['openrouter.ai/api/v1/videos' => Http::sequence()
            ->push(['id' => 'clip_1', 'status' => 'pending'])
            ->push(['id' => 'clip_2', 'status' => 'pending'])
            ->push(['id' => 'clip_3', 'status' => 'pending'])]);
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING]);

        foreach (montagePlan() as $index => $plan) {
            Keyframe::factory()->for($shot)->create(['position' => $index + 1, ...$plan])
                ->addMediaFromString(stillPng())->usingFileName("still-{$index}.png")->toMediaCollection(Keyframe::RENDERS);
        }

        (new GenerateVideo($shot))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class));

        Http::assertSentCount(3);
        // Each clip lasts its share of the 7-second shot (2.67 s), rounded up to whole seconds; the join cuts it back.
        Http::assertSent(fn(Request $request) => $request['duration'] === 3);
        Http::assertSent(fn(Request $request) => ($request['frame_images'][0]['frame_type'] ?? null) === 'first_frame'
            && ! isset($request['input_references'])
            && str_contains($request['prompt'], 'welders join a hull section'));
        expect($shot->fresh()->montage_clips)->toEqual([
            ['position' => 1, 'job_id' => 'clip_1', 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'status' => 'pending'],
            ['position' => 3, 'job_id' => 'clip_3', 'status' => 'pending'],
        ])->and($shot->fresh()->video_job_id)->toBeNull();
        Queue::assertPushed(PollShotClips::class);
    });

    it('waits while clips render and fails the video when one fails', function () {
        Queue::fake();
        $round = 1;
        Http::fake(function () use (&$round) {
            return Http::response($round === 1 ? ['status' => 'in_progress'] : ['status' => 'failed', 'error' => 'Content policy']);
        });
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now(), 'montage_clips' => [
            ['position' => 1, 'job_id' => 'clip_1', 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'status' => 'pending'],
        ]]);

        (new PollShotClips($shot))->handle(app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);
        Queue::assertPushed(PollShotClips::class);

        $round = 2;

        (new PollShotClips($shot->fresh()))->handle(app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        expect($shot->fresh())->status->toBe(ShotStatus::KEYFRAMES_READY)->montage_clips->toBeNull()->video_error->not->toBeNull();
    });

    it('cuts each clip to its share of the shot and joins them with crossfades', function () {
        Queue::fake();
        $directory = storage_path('tmp/montage-test');
        File::ensureDirectoryExists($directory);
        Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=4', '-pix_fmt', 'yuv420p', "{$directory}/clip.mp4"])->throw();
        $clip = File::get("{$directory}/clip.mp4");
        File::deleteDirectory($directory);
        Http::fake([
            'openrouter.ai/api/v1/videos/*/content*' => Http::response($clip),
            'openrouter.ai/api/v1/videos/*' => Http::response(['status' => 'completed', 'usage' => ['cost' => 0.1]]),
        ]);
        $shot = montageShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now(), 'montage_clips' => [
            ['position' => 1, 'job_id' => 'clip_1', 'status' => 'pending'],
            ['position' => 2, 'job_id' => 'clip_2', 'status' => 'pending'],
            ['position' => 3, 'job_id' => 'clip_3', 'status' => 'pending'],
        ]]);

        (new PollShotClips($shot))->handle(app(OpenRouterVideoClient::class), app(ClipJoiner::class));

        $shot->refresh();
        $video = $shot->getFirstMedia(Shot::VIDEO);

        // Seven seconds over three stills: each shows about 2.67 s, the crossfades overlapping by half a second.
        expect($shot->status)->toBe(ShotStatus::VIDEO_READY)
            ->and($shot->montage_clips)->toBeNull()
            ->and($shot->getMedia(Shot::MONTAGE_CLIPS))->toBeEmpty()
            ->and($video)->not->toBeNull()
            ->and($video->getCustomProperty(Shot::VIDEO_SECONDS))->toEqualWithDelta(7.0, 0.1)
            ->and((float) $shot->generations()->where('kind', 'video')->value('cost'))->toEqualWithDelta(0.3, 0.001);
    })->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

    it('spreads the shot length over the stills', function () {
        expect(PollShotClips::clipLength(7, 3))->toBe(2.67)
            ->and(PollShotClips::clipLength(4, 6))->toBe(1.5);
    });
});
