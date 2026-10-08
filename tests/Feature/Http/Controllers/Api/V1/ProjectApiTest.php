<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Shot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['title' => 'Welcome to Damen Naval']);
    $this->token = $this->director->createToken('e-learning')->plainTextToken;
});

/**
 * A shot with a finished video, a spoken track for the current text and a drawn keyframe.
 */
function finishedShot(Project $project): Shot
{
    $shot = Shot::factory()->for($project)->create(['position' => 1, 'title' => 'Security', 'status' => ShotStatus::VIDEO_READY, 'voice_over' => 'Security is your first stop.']);
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
    $shot->addMediaFromString($mp4)->usingFileName('shot-1.mp4')->withCustomProperties([Shot::VIDEO_SECONDS => 6.5])->toMediaCollection(Shot::VIDEO);
    $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x64" . str_repeat("\x00", 400);
    $shot->addMediaFromString($mp3)->usingFileName('nl.mp3')->withCustomProperties(['locale' => 'nl-NL', 'script' => 'Security is your first stop.', 'text' => 'Beveiliging is je eerste aanspreekpunt.'])->toMediaCollection(Shot::VOICE_OVERS);
    $shot->addMediaFromString($mp3)->usingFileName('de.mp3')->withCustomProperties(['locale' => 'de-DE', 'script' => 'An older text.'])->toMediaCollection(Shot::VOICE_OVERS);
    $keyframe = Keyframe::factory()->for($shot)->create(['position' => 1]);
    $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);

    return $shot;
}

it('lists the director\'s own projects with their token', function () {
    Project::factory()->ownedBy(Director::factory()->create())->create(['title' => 'Someone else\'s']);
    Shot::factory()->for($this->project)->count(2)->create();

    withToken($this->token)->getJson(route('api.v1.projects.index'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $this->project->sqid)
        ->assertJsonPath('0.title', 'Welcome to Damen Naval')
        ->assertJsonPath('0.shotsCount', 2);
});

it('stamps a director\'s token with the tenant and refuses it for another tenant', function () {
    $stored = PersonalAccessToken::query()->where('tokenable_type', 'director')->firstOrFail();

    expect($stored->tenant_id)->toBe(Tenant::current()->getKey());

    $stored->forceFill(['tenant_id' => Tenant::current()->getKey() + 1000])->save();

    withToken($this->token)->getJson(route('api.v1.projects.index'))->assertUnauthorized();
});

it('needs a token and refuses operator tokens', function () {
    getJson(route('api.v1.projects.index'))->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());

    getJson(route('api.v1.projects.index'))->assertForbidden();
});

it('lists a project\'s shots in order, not another director\'s', function () {
    $shot = finishedShot($this->project);
    Shot::factory()->for($this->project)->create(['position' => 2, 'status' => ShotStatus::STORYLINE_READY]);

    withToken($this->token)->getJson(route('api.v1.projects.shots.index', $this->project))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $shot->sqid)
        ->assertJsonPath('0.videoReady', true)
        ->assertJsonPath('0.videoVersion', $shot->video()->id)
        ->assertJsonPath('1.videoReady', false);

    $other = Project::factory()->ownedBy(Director::factory()->create())->create();

    withToken($this->token)->getJson(route('api.v1.projects.shots.index', $other))->assertForbidden();
});

it('gives a shot\'s files as signed links that only work with the token', function () {
    $shot = finishedShot($this->project);

    $assets = withToken($this->token)->getJson(route('api.v1.projects.shots.assets', [$this->project, $shot]))
        ->assertOk()
        ->assertJsonPath('video.seconds', 6.5)
        ->assertJsonPath('voiceOverText', 'Security is your first stop.')
        ->assertJsonCount(1, 'voiceOvers')
        ->assertJsonPath('voiceOvers.0.locale', 'nl-NL')
        ->json();

    expect($assets['video']['url'])->toContain('signature=')->toContain('download=SH010')
        ->and($assets['thumbnailUrl'])->toContain('/thumbnail')
        ->and($assets['voiceOverTexts'])->toBe([['locale' => 'nl-NL', 'text' => 'Beveiliging is je eerste aanspreekpunt.']])
        ->and($assets['keyframes'])->toHaveCount(1)
        ->and($assets['keyframes'][0])->toMatchArray(['position' => 1, 'title' => $shot->keyframes()->first()->title])
        ->and($assets['keyframes'][0]['imageUrl'])->not->toContain('/thumbnail')->toContain('keyframe%201');

    withToken($this->token)->get($assets['keyframes'][0]['imageUrl'])->assertOk();

    withToken($this->token)->get($assets['video']['url'])->assertOk()->assertDownload('SH010 Security.mp4');
    withToken($this->token)->get(strtok($assets['video']['url'], '?'))->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->flushHeaders()->get($assets['video']['url'], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('keeps another project\'s shot out of a project\'s assets', function () {
    $shot = finishedShot(Project::factory()->ownedBy($this->director)->create());

    withToken($this->token)->getJson(route('api.v1.projects.shots.assets', [$this->project, $shot]))->assertNotFound();
});

it('creates a token for a director from the command line', function () {
    artisan('directors:api-token', ['email' => $this->director->email, '--tenant' => Tenant::current()->getKey()])
        ->expectsOutputToContain('shown only once')
        ->assertSuccessful();

    expect($this->director->tokens()->count())->toBe(2);
});
