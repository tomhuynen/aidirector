<?php

declare(strict_types=1);

use App\Ai\Agents\VideoPromptWriter;
use App\Ai\Briefs\BodyRules;
use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\DownloadVideo;
use App\Jobs\GenerateVideo;
use App\Jobs\PollVideos;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Video\LumaVideoClient;
use App\Support\Video\OpenRouterVideoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();
    Illuminate\Support\Facades\Config::set('services.luma.key', 'luma-key');

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT]);
});

/**
 * Runs the video job with the clients the app uses.
 */
function generateVideo(Shot $shot): void
{
    (new GenerateVideo($shot))->handle(app(LumaVideoClient::class), app(OpenRouterVideoClient::class), app(App\Ai\KeyframePainter::class), app(App\Support\Video\StillVideo::class));
}

function pngOf(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 140, 160));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function renderedShot(Project $project, int $count = 2, array $attributes = []): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'duration' => 6,
        'storyline' => ['keyframes' => [['title' => 'Moment 1', 'description' => 'The man in moment 1.', 'seconds' => 4], ['title' => 'Moment 2', 'description' => 'The man in moment 2.', 'seconds' => 2]]],
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
        'summary' => 'The man lights a cigarette, notices the sign and puts it out.',
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
        $shot = renderedShot($this->project, 1);
        Keyframe::factory()->for($shot)->create(['position' => 2]);

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
        Illuminate\Support\Facades\Config::set('pipeline.voice_over.automatic', true);
        Queue::fake([App\Jobs\GenerateVoiceOverAudio::class, PollVideos::class]);
        VideoPromptWriter::fake([videoPromptParts()]);
        Http::fake(['agents.lumalabs.ai/v1/generations' => Http::response(['id' => 'gen-1', 'state' => 'queued'], 201)]);

        $this->project->settings = new App\Support\Projects\ProjectSettings(voiceOver: true, voiceOverLocales: ['nl-NL', 'de-DE']);
        $this->project->save();
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'voice_over' => 'Wait behind the line.']);

        generateVideo($shot);

        Queue::assertPushed(App\Jobs\GenerateVoiceOverAudio::class, fn($job) => $job->locale === 'nl-NL');
        Queue::assertPushed(App\Jobs\GenerateVoiceOverAudio::class, fn($job) => $job->locale === 'de-DE');
        expect($shot->fresh()->voice_over_tracks)->toEqual(['nl-NL' => ['status' => 'pending'], 'de-DE' => ['status' => 'pending']]);
    });

    it('pins the start frame on the first frame and the end frame on the last, so Ray times the act, with a prompt that forbids inventing anything', function () {
        Queue::fake([PollVideos::class]);
        VideoPromptWriter::fake([videoPromptParts()]);
        Http::fake(['agents.lumalabs.ai/v1/generations' => Http::response(['id' => 'gen-1', 'state' => 'queued'], 201)]);

        $this->project->forceFill(['video_resolution' => '1080p'])->save();
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING]);

        generateVideo($shot);

        $shot->refresh();

        expect($shot->video_job_id)->toBe('gen-1')
            ->and($shot->video_summary)->toBe('The man lights a cigarette, notices the sign and puts it out.')
            ->and($shot->video_submitted_at)->not->toBeNull()
            ->and($shot->video_prompt)
            ->toContain('Create one continuous, natural 10-second animation of one shot, from the supplied start frame to the supplied end frame.')
            ->toContain('the very first frame of the video must match the start frame exactly, and the very last frame of the video must match the end frame exactly')
            ->toContain('The act plays at the speed real people move at, never slowed down and never stretched to fill the clip: the end frame is reached as soon as the act is done, and from there everything holds still, exactly as in the end frame, until the clip ends.')
            ->toContain('Visual style: Preserve the soft 3D illustration style')
            ->toContain('Action: The man lights the cigarette (keyframe 1)')
            ->toContain('The no-smoking sign stays on the yellow barrier.')
            ->toContain('Do not introduce new story events or visual elements.')
            ->toContain('Let the people act, as animated characters do: facial expressions that fit the moment')
            ->not->toContain('reference images')
            ->not->toContain('storyboard');

        VideoPromptWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "The frames:\nStart frame. Moment 1: The man in moment 1.\nEnd frame. Moment 2: The man in moment 2.")
            && count($prompt->attachments) === 2
            && str_contains($prompt->prompt, 'Where a description and its image differ, the image is right')
            && str_contains((string) $prompt->agent->instructions(), 'plays one shot from its start frame to its end frame')
            && str_contains((string) $prompt->agent->instructions(), 'describe every person exactly as drawn in the frames')
            && str_contains((string) $prompt->agent->instructions(), 'Describe a thing that must not move as a fixed part of the place')
            && str_contains((string) $prompt->agent->instructions(), 'presses the handle down once, the door is locked and does not move, he lets go'));

        // Six seconds planned: the shortest clip Ray renders that holds them, with the end frame on its last frame.
        Http::assertSent(fn(Request $request) => $request->url() === 'https://agents.lumalabs.ai/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer luma-key')
            && $request['model'] === 'ray-3.2'
            && $request['aspect_ratio'] === '9:16'
            && $request['video']['resolution'] === '1080p'
            && $request['video']['duration'] === '10s'
            && $request['video']['keyframe_indexes'] === [0, 240]
            && count($request['video']['keyframes']) === 2
            && $request['video']['keyframes'][0]['media_type'] === 'image/png'
            && $request['prompt'] === $shot->video_prompt);

        Queue::assertPushed(PollVideos::class);
    });

    it('holds the start frame when the shot has no end frame, and lets Ray time a shot the planner did not time', function () {
        VideoPromptWriter::fake([videoPromptParts(), videoPromptParts()]);
        Http::fake(['agents.lumalabs.ai/v1/generations' => Http::response(['id' => 'gen-1', 'state' => 'queued'], 201)]);
        $held = renderedShot($this->project, 1, ['status' => ShotStatus::VIDEO_PENDING, 'storyline' => ['keyframes' => [['title' => 'Moment 1', 'description' => 'The man in moment 1.']]]]);
        $untimed = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'storyline' => ['keyframes' => [['title' => 'Moment 1', 'description' => 'One.'], ['title' => 'Moment 2', 'description' => 'Two.']]]]);

        generateVideo($held);
        generateVideo($untimed);

        expect($held->fresh()->video_prompt)->toContain('The shot holds the start frame: nothing changes')
            ->and($untimed->fresh()->video_prompt)->toContain('The act plays at the speed real people move at');
        VideoPromptWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "The one frame the shot holds:\nStart frame. Moment 1"));
        // One frame: pinned on the first frame and held to where the shot ends.
        Http::assertSent(fn(Request $request) => $request['video']['keyframe_indexes'] === [0, 144] && count($request['video']['keyframes']) === 2);
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
            'agents.lumalabs.ai/v1/generations/vid_a' => Http::response(['id' => 'vid_a', 'state' => 'dreaming']),
            'agents.lumalabs.ai/v1/generations/vid_b' => Http::response(['id' => 'vid_b', 'state' => 'queued']),
        ]);

        $a = renderingShot($this->project, 'vid_a');
        $b = renderingShot($this->project, 'vid_b');
        renderingShot($this->project, 'vid_new', secondsAgo: 5);

        (new PollVideos())->handle(app(LumaVideoClient::class));

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
            'agents.lumalabs.ai/v1/generations/vid_done' => Http::response(['id' => 'vid_done', 'state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://cdn.luma.test/out.mp4']]]),
            'agents.lumalabs.ai/v1/generations/vid_bad' => Http::response(['id' => 'vid_bad', 'state' => 'failed', 'failure_reason' => 'Content moderated']),
            'agents.lumalabs.ai/v1/generations/vid_down' => Http::response('error code: 502', 502),
        ]);

        $done = renderingShot($this->project, 'vid_done');
        $bad = renderingShot($this->project, 'vid_bad');
        $down = renderingShot($this->project, 'vid_down');

        (new PollVideos())->handle(app(LumaVideoClient::class));

        Queue::assertPushed(DownloadVideo::class, fn(DownloadVideo $job) => $job->shot->is($done) && $job->jobId === 'vid_done');
        expect($bad->fresh())->status->toBe(ShotStatus::KEYFRAMES_READY)->video_error->not->toBeNull()
            ->and($bad->generations()->where('error', 'Content moderated')->where('provider', 'luma')->where('model', 'ray-3.2')->count())->toBe(1)
            ->and($down->fresh()->status)->toBe(ShotStatus::VIDEO_PENDING);

        // Only the unreadable one is left to check; the finished one is not downloaded twice.
        Queue::assertPushed(PollVideos::class, 1);
        (new PollVideos())->handle(app(LumaVideoClient::class));
        Queue::assertPushed(DownloadVideo::class, 1);
    });

    it('gives up when the video takes too long', function () {
        Http::fake(['agents.lumalabs.ai/v1/generations/vid_123' => Http::response(['id' => 'vid_123', 'state' => 'dreaming'])]);

        $shot = renderingShot($this->project, 'vid_123', secondsAgo: 3600);

        (new PollVideos())->handle(app(LumaVideoClient::class));

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);
        Queue::assertNotPushed(PollVideos::class);
    });

    it('stores the finished video from the link the render has now, and logs its cost from the price list', function () {
        Http::fake([
            'agents.lumalabs.ai/v1/generations/gen-1' => Http::response(['id' => 'gen-1', 'state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://cdn.luma.test/out.mp4']]]),
            'cdn.luma.test/*' => Http::response(fakeMp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $shot = renderingShot($this->project, 'gen-1');
        $shot->update(['duration' => 10]);

        (new DownloadVideo($shot, 'gen-1'))->handle(app(LumaVideoClient::class));

        $shot->refresh();
        $generation = $shot->generations()->where('kind', 'video')->firstOrFail();

        expect($shot->status)->toBe(ShotStatus::VIDEO_READY)
            ->and(Storage::disk(Disk::TENANT->value)->get($shot->video()->getPathRelativeToRoot()))->toBe(fakeMp4())
            ->and($generation)->provider->toBe('luma')->model->toBe('ray-3.2')->cost->toEqual(0.45)->prompt->toBe('The prompt');
    });

    it('ignores a download for a render that was replaced', function () {
        Http::fake();

        $shot = renderingShot($this->project, 'vid_new');

        (new DownloadVideo($shot, 'vid_old'))->handle(app(LumaVideoClient::class));

        Http::assertNothingSent();
    });

    it('cuts the clip where the act ends, as Ray timed it, and a held frame at the planned length', function (int $frames, string $source, float $expected) {
        $clip = storage_path('framework/testing/luma-10s.mp4');
        @mkdir(dirname($clip), 0777, true);
        Illuminate\Support\Facades\Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', $source, '-pix_fmt', 'yuv420p', $clip])->throw();
        Http::fake([
            'agents.lumalabs.ai/v1/generations/gen-1' => Http::response(['id' => 'gen-1', 'state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://cdn.luma.test/out.mp4']]]),
            'cdn.luma.test/*' => Http::response((string) file_get_contents($clip), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $shot = renderedShot($this->project, $frames, ['status' => ShotStatus::VIDEO_PENDING, 'video_job_id' => 'gen-1', 'video_submitted_at' => now(), 'video_prompt' => 'The prompt']);

        (new DownloadVideo($shot, 'gen-1'))->handle(app(LumaVideoClient::class));

        $stored = $shot->fresh()->video();
        File::put($clip, Storage::disk(Disk::TENANT->value)->get($stored->getPathRelativeToRoot()));
        $seconds = (float) Illuminate\Support\Facades\Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $clip])->output();

        expect($seconds)->toEqualWithDelta($expected, 0.1)
            ->and((float) $stored->getCustomProperty(Shot::VIDEO_SECONDS))->toEqualWithDelta($expected, 0.1);
        @unlink($clip);
    })->with([
        // Two seconds of movement, then the end frame held: cut once the averaged movement has died down, with a short tail.
        'an act that ends early' => [2, 'testsrc=s=64x64:d=2:r=24[a];color=c=blue:s=64x64:d=8:r=24[b];[a][b]concat=n=2:v=1', 2.46],
        'one frame held' => [1, 'color=c=blue:s=64x64:d=10:r=24', 6.0],
    ])->skip(fn() => ! Illuminate\Support\Facades\Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');
});

describe('page', function () {
    it('links the video', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_READY, 'video_prompt' => 'The prompt']);
        $shot->addMediaFromString(fakeMp4())->usingFileName('shot.mp4')->toMediaCollection(Shot::VIDEO);

        $response = actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('shot.videoUrl', fn(string $url) => str_contains($url, 'signature='))
                ->where('shot.links.videoGenerate', route('public.shots.video.generate', [$this->project, $shot])));

        actingAs($this->director, 'director')
            ->get($response->viewData('page')['props']['shot']['videoUrl'])
            ->assertSuccessful();
    });

    it('shows how long a video usually takes, from the latest videos, and when this one started', function () {
        $shot = renderedShot($this->project, attributes: ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now()->subMinute()]);
        foreach ([100_000, 200_000, 300_000] as $waited) {
            $shot->generations()->create(['director_id' => $this->director->id, 'kind' => 'video', 'provider' => 'openrouter', 'model' => 'm', 'prompt' => 'p', 'duration_ms' => $waited]);
        }

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->where('shot.renderSeconds.video', 200)
                ->where('shot.videoSubmittedAt', $shot->fresh()->video_submitted_at->toIso8601String()));
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

it('has the video prompt writer leave the timing to the video model, without tempo words or a length', function () {
    $shot = renderedShot($this->project);
    $writer = new VideoPromptWriter($shot);

    expect((string) $writer->instructions())->toContain(BodyRules::NO_TEMPO)
        ->and($writer->promptFor($shot->keyframes()->orderBy('position')->get()))->toStartWith('Write the video prompt for this shot.')
        ->not->toContain('seconds');
});
