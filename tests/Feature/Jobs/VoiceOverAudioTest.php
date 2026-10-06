<?php

declare(strict_types=1);

use App\Ai\Agents\VoiceOverTranslator;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\GenerateVoiceOverAudio;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Audio\AudioLength;
use App\Support\Audio\OpenRouterSpeechClient;
use App\Support\Projects\ProjectSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['default_duration' => 5]);
    $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['nl-NL', 'en-GB']);
    $this->project->save();
});

function fakeMp3(): string
{
    return "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x64" . str_repeat("\x00", 400);
}

function spokenShot(Project $project): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::VIDEO_READY,
        'voice_over' => 'Wait behind the line until the load has passed.',
    ]);
}

/**
 * Pretends every spoken track lasts the given seconds and records speed-ups.
 */
function fakeAudioLength(float $seconds): object
{
    $fake = new class ($seconds) extends AudioLength {
        public array $speedUps = [];

        public function __construct(private readonly float $length) {}

        public function seconds(string $audio): float
        {
            return $this->length;
        }

        public function speedUp(string $audio, float $factor): string
        {
            $this->speedUps[] = round($factor, 2);

            return $audio;
        }
    };

    app()->instance(AudioLength::class, $fake);

    return $fake;
}

it('speaks an English track as written and keeps it with its locale', function () {
    Http::fake(['openrouter.ai/api/v1/audio/speech' => Http::response(fakeMp3(), 200, ['Content-Type' => 'audio/mpeg'])]);
    $length = fakeAudioLength(3.0);
    VoiceOverTranslator::fake();
    $shot = spokenShot($this->project);

    (new GenerateVoiceOverAudio($shot, 'en-GB'))->handle(app(OpenRouterSpeechClient::class), $length);

    $track = $shot->fresh()->getFirstMedia(Shot::VOICE_OVERS);

    expect($track->getCustomProperty('locale'))->toBe('en-GB')
        ->and($track->getCustomProperty('text'))->toBe('Wait behind the line until the load has passed.')
        ->and($shot->fresh()->voice_over_tracks)->toBe(['en-GB' => ['status' => 'ready']])
        ->and($length->speedUps)->toBe([]);

    VoiceOverTranslator::assertNeverPrompted();
    Http::assertSent(fn(Request $request) => $request['voice'] === 'en-GB-Emily:MAI-Voice-2.1' && $request['model'] === config('pipeline.models.voice'));
});

it('translates other languages and speeds a slightly long track up to fit the clip', function () {
    Http::fake(['openrouter.ai/api/v1/audio/speech' => Http::response(fakeMp3(), 200, ['Content-Type' => 'audio/mpeg'])]);
    $length = fakeAudioLength(5.5);
    VoiceOverTranslator::fake([['text' => 'Wacht achter de lijn tot de last voorbij is.']]);
    $shot = spokenShot($this->project);

    (new GenerateVoiceOverAudio($shot, 'nl-NL'))->handle(app(OpenRouterSpeechClient::class), $length);

    expect($shot->fresh()->getFirstMedia(Shot::VOICE_OVERS)->getCustomProperty('text'))->toBe('Wacht achter de lijn tot de last voorbij is.')
        ->and($length->speedUps)->toBe([1.1]);
    Http::assertSent(fn(Request $request) => $request['voice'] === 'nl-NL-Harper:MAI-Voice-2.1' && $request['input'] === 'Wacht achter de lijn tot de last voorbij is.');
});

it('says a much too long translation shorter before speeding it up', function () {
    Http::fake(['openrouter.ai/api/v1/audio/speech' => Http::response(fakeMp3(), 200, ['Content-Type' => 'audio/mpeg'])]);
    $length = fakeAudioLength(8.0);
    VoiceOverTranslator::fake([['text' => 'Een veel te lange vertaling.'], ['text' => 'Korter.']]);
    $shot = spokenShot($this->project);

    (new GenerateVoiceOverAudio($shot, 'nl-NL'))->handle(app(OpenRouterSpeechClient::class), $length);

    expect($shot->fresh()->getFirstMedia(Shot::VOICE_OVERS)->getCustomProperty('text'))->toBe('Korter.')
        ->and($length->speedUps)->toBe([1.25]);
    VoiceOverTranslator::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Say the same in fewer words'));
});

it('marks a language as failed with the reason', function () {
    $shot = spokenShot($this->project);

    (new GenerateVoiceOverAudio($shot, 'nl-NL'))->failed(new RuntimeException('Voice model down'));

    expect($shot->fresh()->voice_over_tracks)->toEqual(['nl-NL' => ['status' => 'failed', 'error' => 'Voice model down']]);
});

it('starts every voice-over language again on request and shows the tracks', function () {
    Queue::fake();
    $shot = spokenShot($this->project);

    actingAs($this->director, 'director')
        ->post(route('public.shots.voice-over.audio', [$this->project, $shot]))
        ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

    Queue::assertPushed(GenerateVoiceOverAudio::class, 2);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page
            ->where('shot.voiceOverTracks.0.locale', 'nl-NL')
            ->where('shot.voiceOverTracks.0.status', 'pending')
            ->where('shot.voiceOverTracks.1.name', 'English (United Kingdom)'));
});

it('marks a track whose text changed since it was spoken', function () {
    $shot = spokenShot($this->project);
    $shot->addMediaFromString(fakeMp3())->usingFileName('nl.mp3')
        ->withCustomProperties(['locale' => 'nl-NL', 'script' => 'An older voice-over.', 'text' => 'Ouder.'])
        ->toMediaCollection(Shot::VOICE_OVERS);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page
            ->where('shot.voiceOverTracks.0.status', 'ready')
            ->where('shot.voiceOverTracks.0.outdated', true)
            ->where('shot.voiceOverTracks.0.audioUrl', fn(string $url) => str_contains($url, 'signature='))
            ->where('shot.voiceOverTracks.1.status', 'missing'));
});

it('shows no tracks while the voice-over is off', function () {
    $this->project->settings = new ProjectSettings(voiceOver: false, voiceOverLocales: ['nl-NL']);
    $this->project->save();
    $shot = spokenShot($this->project);

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.voiceOverTracks', []));
});

it('says which language has no voice instead of sending an empty one', function () {
    Http::fake();
    config(['pipeline.voice_over.voices' => []]);
    $shot = spokenShot($this->project);

    expect(fn() => (new GenerateVoiceOverAudio($shot, 'en-GB'))->handle(app(OpenRouterSpeechClient::class), fakeAudioLength(3.0)))
        ->toThrow(RuntimeException::class, 'No voice is set up for en-GB.');
    Http::assertNothingSent();
});
