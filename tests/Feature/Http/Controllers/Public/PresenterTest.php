<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Agents\VoiceJudge;
use App\Ai\Agents\VoiceOverTranslator;
use App\Ai\KeyframePainter;
use App\Enums\AspectRatio;
use App\Enums\Disk;
use App\Enums\ElementType;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateRemainingKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateVideo;
use App\Jobs\JudgeElementVoice;
use App\Jobs\PollShotClips;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Projects\ProjectSettings;
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
    $this->project = Project::factory()->ownedBy($this->director)->create(['aspect_ratio' => AspectRatio::PORTRAIT]);
});

function presenterPng(): string
{
    $image = imagecreatetruecolor(90, 160);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 180, 160));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @return list<array{title: string, description: string, elements: list<string>}>
 */
function presenterPlan(): array
{
    return [['title' => 'Welcome', 'description' => 'The Damen host faces the camera from the chest up, smiling, the Assembly Hall softly blurred behind him.', 'elements' => ['Damen host', 'Assembly Hall']]];
}

function presenterShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'kind' => ShotKind::PRESENTER,
        'status' => ShotStatus::KEYFRAMES_PENDING,
        'voice_over' => 'Your safety comes first at Damen Naval.',
        'chosen_storyline' => ['title' => 'Welcome', 'storyline' => 'The host welcomes the viewer.'],
        'storyline' => ['framing' => ['size' => 'medium', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 5], 'keyframes' => presenterPlan()],
        ...$attributes,
    ]);
}

it('plans one still with the presenter rules', function () {
    Queue::fake();
    StorylineWriter::fake([['title' => 'Welcome', 'kind' => 'presenter', 'storyline' => 'The host welcomes the viewer.', 'framing' => ['size' => 'medium', 'spot' => '', 'light' => 'as the visual style', 'seconds' => 5], 'keyframes' => presenterPlan()]]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_PENDING, 'kind' => ShotKind::PRESENTER]);

    (new GenerateStoryline($shot, keepKind: true))->handle();

    expect($shot->fresh()->kind)->toBe(ShotKind::PRESENTER);
    StorylineWriter::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Exactly one keyframe: the still the presenter speaks from.')
        && ! str_contains((string) $prompt->agent->instructions(), 'Never explain with a board'));
});

it('keeps the voice a person speaks with in their settings, judged from the picture or chosen in the form', function () {
    Queue::fake();
    VoiceJudge::fake([['voice' => 'female']]);
    $host = Element::factory()->for($this->project)->create(['name' => 'Damen host', 'type' => ElementType::PERSON]);
    $host->addMediaFromString(presenterPng())->usingFileName('host.png')->toMediaCollection(Element::REFERENCE);

    expect(JudgeElementVoice::judge($host))->toBe('female')
        ->and($host->fresh()->settings->voice)->toBe('female');

    actingAs($this->director, 'director')
        ->post(route('public.projects.elements.update', [$this->project, $host]), ['name' => $host->name, 'description' => $host->description, 'voice' => 'male'])
        ->assertSessionHasNoErrors();

    expect($host->fresh()->settings->voice)->toBe('male');
    // A voice once judged or chosen is not judged again.
    expect(JudgeElementVoice::judge($host->fresh()))->toBe('male');
});

it('draws the still frontal and close, with the place only as a blurred background', function () {
    Config::set('pipeline.keyframe_check', true);
    Image::fake(fn() => base64_encode(presenterPng()));
    KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);
    Queue::fake([GenerateRemainingKeyframes::class]);
    $shot = presenterShot($this->project, ['status' => ShotStatus::FIRST_KEYFRAME_PENDING]);
    $host = Element::factory()->for($this->project)->create(['name' => 'Damen host', 'type' => ElementType::PERSON]);
    $host->addMediaFromString(presenterPng())->usingFileName('host.png')->toMediaCollection(Element::REFERENCE);

    (new GenerateKeyframes($shot))->handle();
    (new GenerateRemainingKeyframes($shot->fresh()))->handle(app(KeyframePainter::class));

    expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY)
        ->and($shot->keyframes()->with('media')->first()->render())->not->toBeNull();
    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('A presenter shot for an e-learning film')
        && $prompt->contains('a medium close-up from the chest up')
        && $prompt->contains('Draw Damen host exactly like it'));
    KeyframeChecker::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'This keyframe is the still of a presenter'));
});

it('renders a lip-synced clip per language with the voice of that language', function () {
    Queue::fake();
    VoiceOverTranslator::fake([['text' => 'Bij Damen Naval staat jouw veiligheid voorop.']]);
    Http::fake(['openrouter.ai/api/v1/videos' => Http::sequence()->push(['id' => 'avatar_1'])->push(['id' => 'avatar_2'])]);
    $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['en-GB', 'nl-NL']);
    $this->project->save();
    $shot = presenterShot($this->project, ['status' => ShotStatus::VIDEO_PENDING]);
    $host = Element::factory()->for($this->project)->create(['name' => 'Damen host', 'type' => ElementType::PERSON, 'settings' => ['voice' => 'female']]);
    $still = Keyframe::factory()->for($shot)->create(['position' => 1]);
    $still->addMediaFromString(presenterPng())->usingFileName('still.png')->toMediaCollection(Keyframe::RENDERS);
    $still->elements()->attach($host);

    (new GenerateVideo($shot))->handle(app(OpenRouterVideoClient::class), app(KeyframePainter::class));

    // The person's own voice, kept per language so they sound the same in every shot.
    expect($host->fresh()->settings->voices)->toEqual(['nl' => Config::get('pipeline.presenter.voices.female.*'), 'en' => Config::get('pipeline.presenter.voices.female.en')]);
    Http::assertSent(fn(Request $request) => $request['model'] === 'heygen/avatar-iv'
        && $request['prompt'] === 'Your safety comes first at Damen Naval.'
        && $request['provider']['options']['heygen']['voice_id'] === Config::get('pipeline.presenter.voices.female.en')
        && $request['input_references'][0]['type'] === 'image_url');
    Http::assertSent(fn(Request $request) => $request['prompt'] === 'Bij Damen Naval staat jouw veiligheid voorop.'
        && $request['provider']['options']['heygen']['voice_id'] === Config::get('pipeline.presenter.voices.female.*'));
    expect(collect($shot->fresh()->montage_clips)->pluck('locale')->sort()->values()->all())->toBe(['en-GB', 'nl-NL'])
        ->and(collect($shot->fresh()->montage_clips)->pluck('status')->unique()->all())->toBe(['pending']);
    Queue::assertPushed(PollShotClips::class);
});

it('keeps every language with its sound and shows them on the shot', function () {
    Queue::fake();
    $directory = storage_path('tmp/presenter-test');
    File::ensureDirectoryExists($directory);
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=1', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-shortest', '-pix_fmt', 'yuv420p', "{$directory}/clip.mp4"])->throw();
    $clip = File::get("{$directory}/clip.mp4");
    File::deleteDirectory($directory);
    Http::fake([
        'openrouter.ai/api/v1/videos/*/content*' => Http::response($clip),
        'openrouter.ai/api/v1/videos/*' => Http::response(['status' => 'completed', 'usage' => ['cost' => 0.3]]),
    ]);
    $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['en-GB', 'nl-NL']);
    $this->project->save();
    $shot = presenterShot($this->project, ['status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now(), 'montage_clips' => [
        ['locale' => 'en-GB', 'job_id' => 'avatar_en', 'status' => 'pending'],
        ['locale' => 'nl-NL', 'job_id' => 'avatar_nl', 'status' => 'pending'],
    ]]);

    (new PollShotClips($shot))->handle(app(OpenRouterVideoClient::class), app(ClipJoiner::class));

    $shot->refresh();
    $languages = $shot->getMedia(Shot::PRESENTER_VIDEOS);

    expect($shot->status)->toBe(ShotStatus::VIDEO_READY)
        ->and($languages->map(fn($video) => $video->getCustomProperty('locale'))->all())->toBe(['en-GB', 'nl-NL'])
        ->and(Storage::disk($languages->first()->disk)->get($languages->first()->getPathRelativeToRoot()))->toBe($clip)
        ->and($shot->getFirstMedia(Shot::VIDEO))->not->toBeNull()
        ->and($shot->getMedia(Shot::MONTAGE_CLIPS))->toBeEmpty()
        ->and($shot->generations()->where('kind', 'video')->value('model'))->toBe('heygen/avatar-iv');

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.languageVideos.1.locale', 'nl-NL')->where('shot.languageVideos.1.name', 'Dutch (Netherlands)'));
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

it('switches to a presenter when the plan agreed in the chat says so, until the keyframes are drawn', function () {
    Queue::fake();
    App\Ai\Agents\PlanDirector::fake([[
        'reply' => 'The plan is written.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [],
        'proposal' => ['takeaway' => '', 'kind' => 'presenter', 'storyline' => 'The plan.', 'seconds' => 4, 'setting_from' => null, 'keyframes' => presenterPlan()],
    ]]);
    $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY, 'kind' => ShotKind::SCENE, 'storyline' => ['keyframes' => presenterPlan()]]);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'let the manager say it'])
        ->assertOk();

    expect($shot->fresh()->kind)->toBe(ShotKind::PRESENTER);

    // Once keyframes are drawn, the plan and its kind stay.
    $drawn = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'kind' => ShotKind::SCENE]);
    Keyframe::factory()->for($drawn)->create();

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$this->project, $drawn]), ['message' => 'make it a montage'])
        ->assertJsonValidationErrors('message');
});

it('offers no moving, no extra keyframes and no separate audio tracks on a presenter', function () {
    Queue::fake();
    $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['en-GB', 'nl-NL']);
    $this->project->save();
    $shot = presenterShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY]);
    // A place left over from when the shot was a scene.
    $shot->addMediaFromString(presenterPng())->usingFileName('place.png')->withCustomProperties([Shot::PLATE_CHOSEN => true])->toMediaCollection(Shot::PLATE);
    Keyframe::factory()->for($shot)->create(['position' => 1])
        ->addMediaFromString(presenterPng())->usingFileName('still.png')->toMediaCollection(Keyframe::RENDERS);

    $shot->startVoiceOverAudio();

    Queue::assertNotPushed(App\Jobs\GenerateVoiceOverAudio::class);
    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.voiceOverTracks', [])->where('keyframes.0.links.move', null));
    actingAs($this->director, 'director')
        ->post(route('public.shots.keyframes.store', [$this->project, $shot]), ['title' => 'Second', 'description' => 'Another still.'])
        ->assertSessionHasErrors('description');
});
