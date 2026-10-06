<?php

declare(strict_types=1);

use App\Ai\Agents\VoiceOverWriter;
use App\Enums\ShotStatus;
use App\Jobs\GenerateVoiceOver;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['default_duration' => 5]);
});

function narratedShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create([
        'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
        'takeaway' => 'Never walk under a suspended load.',
        'chosen_storyline' => ['title' => 'Step back', 'storyline' => 'She steps back behind the line.'],
        ...$attributes,
    ]);
}

it('fits the voice-over to the length of the shot at a calm pace', function () {
    expect(GenerateVoiceOver::maxWords(narratedShot($this->project)))->toBe(11)
        ->and(GenerateVoiceOver::maxWords(narratedShot($this->project, ['duration' => 10])))->toBe(22);
});

it('writes a voice-over that fits', function () {
    VoiceOverWriter::fake([['text' => 'Wait behind the line until the load has passed.']]);
    $shot = narratedShot($this->project);

    (new GenerateVoiceOver($shot))->handle();

    expect($shot->fresh()->voice_over)->toBe('Wait behind the line until the load has passed.');
    VoiceOverWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Takeaway: Never walk under a suspended load.')
        && str_contains((string) $prompt->agent->instructions(), 'At most 11 words'));
});

it('asks once more when it is too long and then keeps whole sentences that fit', function () {
    VoiceOverWriter::fake([
        ['text' => 'Never walk under a load. Always wait behind the marked line until the crane has fully lowered the container.'],
        ['text' => 'Never walk under a load. Always wait behind the marked line until the crane has fully lowered it.'],
    ]);
    $shot = narratedShot($this->project);

    (new GenerateVoiceOver($shot))->handle();

    expect($shot->fresh()->voice_over)->toBe('Never walk under a load.');
    VoiceOverWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'shorten it to at most 11 words'));
});

it('cuts a single long sentence to the word limit', function () {
    expect(GenerateVoiceOver::fit('one two three four five six seven', 4))->toBe('one two three four.');
});

it('writes the voice-over again on request and shows it with its spoken length', function () {
    Queue::fake();
    $shot = narratedShot($this->project, ['status' => ShotStatus::KEYFRAMES_READY, 'voice_over' => 'Old text here.']);

    actingAs($this->director, 'director')
        ->post(route('public.shots.voice-over', [$this->project, $shot]))
        ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

    expect($shot->fresh()->voice_over)->toBeNull();
    Queue::assertPushed(GenerateVoiceOver::class);

    $shot->forceFill(['voice_over' => 'Wait behind the line until the load has passed.'])->save();

    actingAs($this->director, 'director')
        ->get(route('public.shots.view', [$this->project, $shot]))
        ->assertInertia(fn($page) => $page->where('shot.voiceOver', 'Wait behind the line until the load has passed.')->where('shot.voiceOverSeconds', 4.1));
});
