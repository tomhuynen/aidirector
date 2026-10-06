<?php

declare(strict_types=1);

use App\Ai\Agents\VideoPromptWriter;
use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\DownloadVideo;
use App\Jobs\GenerateVideo;
use App\Jobs\PollVideo;
use App\Jobs\PollVideos;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Video\OpenRouterVideoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT]);
});

function pngOf(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 140, 160));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function renderedShot(Project $project, int $count = 4, array $attributes = []): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'duration' => 6,
        ...$attributes,
    ]);

    foreach (range(1, $count) as $position) {
        Keyframe::factory()->for($shot)->create([
            'position' => $position,
            'title' => "Moment {$position}",
            'description' => "The man in moment {$position}.",
        ])->addMediaFromString(pngOf(90, 160))->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
    }

    return $shot;
}

function fakeMp4(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
}

function videoPromptParts(): array
{
    return [
        'style' => 'Preserve the soft 3D illustration style of the keyframes.',
        'action' => 'The man lights the cigarette (keyframe 1) and notices the sign (keyframe 2).',
        'details' => 'The no-smoking sign stays on the yellow barrier.',
    ];
}

describe('generate', function () {
    it('starts rendering the video', function () {
        $shot = renderedShot($this->project);

        actingAs($this->director, 'director')
            ->post(route('public.shots.video.generate', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);

        Queue::assertPushed(GenerateVideo::class, fn(GenerateVideo $job) => $job->shot->is($shot));
    });

    it('waits until every keyframe has an image', function () {
        $shot = renderedShot($this->project);
        Keyframe::factory()->for($shot)->create(['position' => 5]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.video.generate', [$this->project, $shot]))
            ->assertSessionHasErrors('video');

        Queue::assertNotPushed(GenerateVideo::class);
    });

    it('does not start a second render while one is running', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.video.generate', [$this->project, $shot]))
            ->assertSessionHasErrors('video');

        Queue::assertNotPushed(GenerateVideo::class);
    });

    it('forbids rendering another director\'s video', function () {
        $shot = renderedShot(Project::factory()->create());

        actingAs($this->director, 'director')
            ->post(route('public.shots.video.generate', [$shot->project, $shot]))
            ->assertForbidden();
    });
});

describe('job', function () {
    it('starts the voice-over tracks of every language while the video renders', function () {
        Queue::fake([App\Jobs\GenerateVoiceOverAudio::class, PollVideos::class]);
        VideoPromptWriter::fake([videoPromptParts()]);
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'vid_123', 'status' => 'pending'])]);

        $this->project->settings = new App\Support\Projects\ProjectSettings(voiceOver: true, voiceOverLocales: ['nl-NL', 'de-DE']);
        $this->project->save();
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'voice_over' => 'Wait behind the line.']);

        (new GenerateVideo($shot))->handle(app(App\Support\Video\OpenRouterVideoClient::class), app(App\Ai\KeyframePainter::class));

        Queue::assertPushed(App\Jobs\GenerateVoiceOverAudio::class, fn($job) => $job->locale === 'nl-NL');
        Queue::assertPushed(App\Jobs\GenerateVoiceOverAudio::class, fn($job) => $job->locale === 'de-DE');
        expect($shot->fresh()->voice_over_tracks)->toEqual(['nl-NL' => ['status' => 'pending'], 'de-DE' => ['status' => 'pending']]);
    });

    it('submits every keyframe as its own reference with a prompt that forbids inventing anything', function () {
        VideoPromptWriter::fake([videoPromptParts()]);
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'vid_123', 'status' => 'pending'])]);

        $this->project->forceFill(['video_resolution' => '1080p'])->save();
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'duration' => 30]);

        (new GenerateVideo($shot))->handle(app(App\Support\Video\OpenRouterVideoClient::class), app(App\Ai\KeyframePainter::class));

        $shot->refresh();

        expect($shot->video_job_id)->toBe('vid_123')
            ->and($shot->video_submitted_at)->not->toBeNull()
            ->and($shot->video_prompt)
            ->toContain('Create one continuous, natural 15-second animation')
            ->toContain('the very first frame of the video must match keyframe 1 exactly, and the very last frame of the video must match keyframe 4 exactly')
            ->toContain('the first frame is keyframe 1 exactly and the last frame is keyframe 4 exactly')
            ->toContain('The 4 reference images are the keyframes of this shot, supplied in chronological order')
            ->not->toContain('collage')
            ->toContain('Keyframe 1 (Moment 1) at about 0.0 s. Keyframe 2 (Moment 2) at about 5.0 s.')
            ->toContain('Visual style: Preserve the soft 3D illustration style')
            ->toContain('Action sequence: The man lights the cigarette (keyframe 1)')
            ->toContain('The no-smoking sign stays on the yellow barrier.')
            ->toContain('Do not introduce new story events or visual elements.')
            ->toContain('Primary objective: faithfully animate the supplied storyboard');

        VideoPromptWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "1. Moment 1: The man in moment 1.\n2. Moment 2")
            && count($prompt->attachments) === 4
            && str_contains((string) $prompt->agent->instructions(), 'describe every person exactly as drawn in the keyframes')
            && str_contains((string) $prompt->agent->instructions(), 'presses the handle down once, the door is locked and does not move, he lets go'));

        Http::assertSent(fn(Request $request) => $request->url() === 'https://openrouter.ai/api/v1/videos'
            && $request['model'] === config('pipeline.models.video')
            && $request['duration'] === 15
            && $request['aspect_ratio'] === '9:16'
            && $request['generate_audio'] === false
            && $request['resolution'] === '1080p'
            && count($request['input_references']) === 4
            && collect($request['input_references'])->every(fn(array $reference) => str_starts_with($reference['image_url']['url'], 'data:image/png;base64,'))
            // No first frame: Wan 3.0 would leave the references out.
            && ! isset($request['frame_images'])
            && $request['prompt'] === $shot->video_prompt);

        Queue::assertPushed(PollVideos::class);
    });

    it('renders at the project resolution or the default', function () {
        $shot = renderedShot($this->project);

        expect($shot->load('project')->videoResolution())->toBe(config('pipeline.video.resolution'));

        $this->project->forceFill(['video_resolution' => '480p'])->save();

        expect($shot->fresh()->load('project')->videoResolution())->toBe('480p');
    });

    it('returns to the keyframes with an error when submitting fails', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING]);

        (new GenerateVideo($shot))->failed(new RuntimeException('Provider down'));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::KEYFRAMES_READY)
            ->and($shot->video_error)->not->toBeNull()
            ->and($shot->generations()->where('kind', 'video')->whereNotNull('error')->count())->toBe(1);
    });
});

/**
 * A shot whose video was submitted the given number of seconds ago.
 */
function renderingShot(Project $project, string $jobId, int $secondsAgo = 60): Shot
{
    return renderedShot($project, attributes: [
        'status' => ShotStatus::VIDEO_PENDING,
        'video_job_id' => $jobId,
        'video_submitted_at' => now()->subSeconds($secondsAgo),
        'video_prompt' => 'The prompt',
    ]);
}

describe('poll', function () {
    it('checks every rendering video in one round and queues the next round', function () {
        Http::fake([
            'openrouter.ai/api/v1/videos/vid_a' => Http::response(['id' => 'vid_a', 'status' => 'in_progress']),
            'openrouter.ai/api/v1/videos/vid_b' => Http::response(['id' => 'vid_b', 'status' => 'in_progress']),
        ]);

        $a = renderingShot($this->project, 'vid_a');
        $b = renderingShot($this->project, 'vid_b');
        renderingShot($this->project, 'vid_new', secondsAgo: 5);

        (new PollVideos())->handle(app(OpenRouterVideoClient::class));

        Http::assertSentCount(2);
        expect($a->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING)
            ->and($b->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);
        Queue::assertPushed(PollVideos::class, 1);
    });

    it('waits for a new video before its first check', function () {
        renderingShot($this->project, 'vid_new', secondsAgo: 5);

        PollVideos::start();

        Queue::assertPushed(PollVideos::class, fn(PollVideos $job) => $job->delay >= 35 && $job->delay <= 40);
    });

    it('hands finished videos to the download and marks failed ones, in the same round', function () {
        Http::fake([
            'openrouter.ai/api/v1/videos/vid_done' => Http::response(['id' => 'vid_done', 'status' => 'completed', 'usage' => ['cost' => 0.42]]),
            'openrouter.ai/api/v1/videos/vid_bad' => Http::response(['id' => 'vid_bad', 'status' => 'failed', 'error' => 'Content policy']),
            'openrouter.ai/api/v1/videos/vid_down' => Http::response('error code: 502', 502),
        ]);

        $done = renderingShot($this->project, 'vid_done');
        $bad = renderingShot($this->project, 'vid_bad');
        $down = renderingShot($this->project, 'vid_down');

        (new PollVideos())->handle(app(OpenRouterVideoClient::class));

        Queue::assertPushed(DownloadVideo::class, fn(DownloadVideo $job) => $job->shot->is($done) && $job->usage === ['cost' => 0.42]);
        expect($bad->fresh())->status->toBe(ShotStatus::KEYFRAMES_READY)->video_error->not->toBeNull()
            ->and($bad->generations()->where('error', 'Content policy')->count())->toBe(1)
            ->and($down->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);

        // Only the unreadable one is left to check; the finished one is not downloaded twice.
        Queue::assertPushed(PollVideos::class, 1);
        (new PollVideos())->handle(app(OpenRouterVideoClient::class));
        Queue::assertPushed(DownloadVideo::class, 1);
    });

    it('gives up when the video takes too long', function () {
        Http::fake(['openrouter.ai/api/v1/videos/vid_123' => Http::response(['id' => 'vid_123', 'status' => 'pending'])]);

        $shot = renderingShot($this->project, 'vid_123', secondsAgo: 3600);

        (new PollVideos())->handle(app(OpenRouterVideoClient::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);
        Queue::assertNotPushed(PollVideos::class);
    });

    it('stores the finished video and logs its cost', function () {
        Http::fake(['openrouter.ai/api/v1/videos/vid_123/content*' => Http::response(fakeMp4(), 200, ['Content-Type' => 'video/mp4'])]);

        $shot = renderingShot($this->project, 'vid_123');

        (new DownloadVideo($shot, 'vid_123', ['cost' => 0.42]))->handle(app(OpenRouterVideoClient::class));

        $shot->refresh();
        $generation = $shot->generations()->where('kind', 'video')->firstOrFail();

        expect($shot->status)->toBe(ShotStatus::VIDEO_READY)
            ->and($shot->video())->not->toBeNull()
            ->and(Storage::disk(Disk::TENANT->value)->get($shot->video()->getPathRelativeToRoot()))->toBe(fakeMp4())
            ->and((float) $generation->cost)->toBe(0.42)
            ->and($generation->prompt)->toBe('The prompt');
    });

    it('ignores a download for a render that was replaced', function () {
        Http::fake();

        $shot = renderingShot($this->project, 'vid_new');

        (new DownloadVideo($shot, 'vid_old'))->handle(app(OpenRouterVideoClient::class));

        Http::assertNothingSent();
    });

    it('hands checks queued before the shared round over to it', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'video_job_id' => 'vid_123']);

        (new PollVideo($shot, 'vid_123', now()->subMinute()->getTimestamp()))->handle();

        expect($shot->fresh()->video_submitted_at)->not->toBeNull();
        Queue::assertPushed(PollVideos::class);
    });
});

describe('page', function () {
    it('links the video and the prompt', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_READY, 'video_prompt' => 'The prompt']);
        $shot->addMediaFromString(fakeMp4())->usingFileName('shot.mp4')->toMediaCollection(Shot::VIDEO);

        $response = actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('shot.videoPrompt', 'The prompt')
                ->where('shot.videoResolution', config('pipeline.video.resolution'))
                ->where('shot.videoResolutions', config('pipeline.video.resolutions'))
                ->where('shot.videoUrl', fn(string $url) => str_contains($url, 'signature='))
                ->where('shot.links.videoGenerate', route('public.shots.video.generate', [$this->project, $shot])));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['shot']['videoUrl'])
            ->assertSuccessful();
    });

    it('offers the video as a download under the shot code and title', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_READY, 'title' => 'Quay Stop Line', 'position' => 7]);
        $shot->addMediaFromString(fakeMp4())->usingFileName('shot.mp4')->toMediaCollection(Shot::VIDEO);

        $response = actingAs($this->director, 'director')->get(route('public.shots.view', [$this->project, $shot]));
        $url = $response->viewData('page')['props']['shot']['videoDownloadUrl'];

        actingAs($this->director, 'director')
            ->get($url)
            ->assertSuccessful()
            ->assertDownload('SH070 Quay Stop Line.mp4');

        actingAs($this->director, 'director')
            ->get(str_replace('SH070', 'SH999', $url))
            ->assertForbidden();
    });

    it('drops the video when the keyframes are planned again', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_READY, 'video_prompt' => 'The prompt', 'video_job_id' => 'vid_1']);
        $shot->addMediaFromString(fakeMp4())->usingFileName('shot.mp4')->toMediaCollection(Shot::VIDEO);

        $shot->forgetKeyframes();
        $shot->refresh();

        expect($shot->video())->toBeNull()
            ->and($shot->video_prompt)->toBeNull()
            ->and($shot->video_job_id)->toBeNull();
    });
});
