<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Ai\Agents\SceneDirector;
use App\Ai\Agents\SequenceReviewer;
use App\Ai\Briefs\RenderCapabilities;
use App\Enums\ShotStatus;
use App\Jobs\DirectSequence;
use App\Models\Director;
use App\Models\Element;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;

const REVIEW_STORY = 'A lady wants to photograph a vessel at the quay with her smartphone. She turns her head, sees a no-photo sign, lowers her smartphone and walks out of view.';

beforeEach(function () {
    Queue::fakeExcept([DirectSequence::class]);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
    $this->shot = Shot::factory()->for($this->project)->create(['position' => 2, 'status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []]]);
    PlanDirector::fake(fn() => ['reply' => 'I lay out the shots for it.', 'stage' => 'kind', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'direct' => REVIEW_STORY, 'proposal' => null]);
});

/**
 * One frame on the quay.
 *
 * @return array<string, mixed>
 */
function quayFrame(string $holds = 'the smartphone'): array
{
    return ['title' => 'Frame', 'summary' => '', 'place' => 'On the quay walkway.', 'cast' => [['name' => 'Lady', 'stands' => 'on the walkway', 'faces' => 'the vessel', 'looks_at' => 'the vessel', 'holds' => $holds, 'hand' => 'right', 'doing' => '']], 'spatial' => '', 'elements' => ['Quay']];
}

/**
 * One shot on the quay as the scene director answers it.
 *
 * @param  list<int>  $shows
 * @param  list<int>  $depicts
 * @return array<string, mixed>
 */
function quayShot(string $title, string $action, array $shows, array $depicts, string $kind = 'scene', string $subject = 'body'): array
{
    return [
        'title' => $title, 'kind' => $kind, 'subject' => $subject, 'purpose' => 'What the viewer must understand.', 'notice' => 'What they must notice.',
        'camera' => 'On the quay walkway behind her, looking along the quay towards the vessel.', 'composition' => 'The vessel in the background.', 'reason' => 'It shows the whole action.',
        'start' => quayFrame(), 'action' => $action, 'end' => quayFrame(), 'seconds' => 4, 'cut' => 'The act is done.', 'shows' => $shows, 'depicts' => $depicts,
    ];
}

/**
 * The scene director's answer for the quay story.
 *
 * @return array<string, mixed>
 */
function quaySequence(string $exit = 'She walks out of view along the walkway.'): array
{
    return [
        'title' => 'No photo',
        'change' => 'She gives up the photo.',
        'facts' => ['She aims at the vessel.', 'She sees the sign.', 'She lowers the phone.'],
        'context' => [],
        'must_not' => ['a photo is taken'],
        'events' => ['she aims the smartphone at the vessel', 'she turns her head', 'she sees the no-photo sign', 'she lowers the smartphone', 'she walks out of view'],
        'new_elements' => [],
        'shots' => [
            quayShot('Aiming', 'She raises the smartphone and aims it at the vessel.', [1], [1]),
            quayShot('The sign', 'She turns her head to the left towards the sign on the wall panel and sees it.', [2], [2, 3], 'close-up', 'face'),
            quayShot('Walking off', "She lowers the smartphone. {$exit}", [3], [4, 5]),
        ],
    ];
}

function tellStory(mixed $test): TestResponse
{
    return actingAs($test->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$test->project, $test->shot]), ['message' => 'she wants a photo but sees the sign'])
        ->assertOk();
}

it('shows the sequence as directed when the review passes', function () {
    SceneDirector::fake([quaySequence()]);
    SequenceReviewer::fake([['passes' => true, 'issues' => []]]);
    Shot::factory()->for($this->project)->create(['position' => 1, 'idea' => 'An insert of the no-photo sign on the wall panel.']);
    $quay = Element::factory()->for($this->project)->place()->create(['name' => 'Quay']);
    $quay->addMediaFromString(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))
        ->usingFileName('quay.png')->withCustomProperties([Element::LAYOUT => 'The walkway runs from the left to an opening on the right.'])->toMediaCollection(Element::REFERENCE);

    tellStory($this)
        ->assertJsonPath('messages.1.sequence.shots.2.action', 'She lowers the smartphone. She walks out of view along the walkway.')
        ->assertJsonPath('messages.1.sequence.issues', []);

    SceneDirector::assertPromptedTimes(1);
    SequenceReviewer::assertPrompted(fn($prompt) => $prompt->model === config('pipeline.models.sequence_reviewer')
        && $prompt->attachments->isEmpty()
        && str_contains($prompt->prompt, "The story, in the director's words: " . REVIEW_STORY)
        && str_contains($prompt->prompt, '- SH010, scene: An insert of the no-photo sign on the wall panel.')
        && str_contains($prompt->prompt, '- Quay: The walkway runs from the left to an opening on the right.')
        && str_contains($prompt->prompt, '"action": "She turns her head to the left towards the sign on the wall panel and sees it."')
        && str_contains((string) $prompt->agent->instructions(), RenderCapabilities::brief())
        && str_contains((string) $prompt->agent->instructions(), 'Every explicit event of the story is played in the action of a shot')
        && str_contains((string) $prompt->agent->instructions(), 'names the shot by its number and says what to fix'));
    expect($this->shot->generations()->where('prompt', 'like', "The story, in the director's words:%")->count())->toBe(1);
});

it('has the scene director repair the sequence once with what the review found', function () {
    $issue = 'Shot 3: the story has her walk out of view, not leave the quay; let her walk out of view along the walkway.';
    SceneDirector::fake([quaySequence('She leaves the quay.'), quaySequence()]);
    SequenceReviewer::fake([['passes' => false, 'issues' => [$issue]], ['passes' => true, 'issues' => []]]);

    tellStory($this)
        ->assertJsonPath('messages.1.sequence.shots.2.action', 'She lowers the smartphone. She walks out of view along the walkway.')
        ->assertJsonPath('messages.1.sequence.issues', []);

    SceneDirector::assertPromptedTimes(2);
    SequenceReviewer::assertPromptedTimes(2);
    SceneDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, $issue) && str_contains($prompt->prompt, 'She leaves the quay.'));
});

it('shows what the review still finds after the repair, and the sequence can still be accepted', function () {
    $issue = 'Shot 2: nothing in the composition shows the sign she reacts to; put the sign on the wall panel in the frame.';
    SceneDirector::fake([quaySequence(), quaySequence()]);
    SequenceReviewer::fake(fn() => ['passes' => false, 'issues' => [$issue]]);

    tellStory($this)->assertJsonPath('messages.1.sequence.issues', [$issue]);

    SceneDirector::assertPromptedTimes(2);

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.sequence', [$this->project, $this->shot]), ['turn' => 1, 'accept' => true])
        ->assertOk()
        ->assertJsonPath('messages.1.sequence.state', 'accepted');

    expect($this->project->shots()->count())->toBe(3);
});

it('shows the sequence without issues when the review fails', function () {
    SceneDirector::fake([quaySequence()]);
    SequenceReviewer::fake(fn() => throw new RuntimeException('The reviewer is down.'));

    tellStory($this)
        ->assertJsonPath('messages.1.sequence.title', 'No photo')
        ->assertJsonPath('messages.1.sequence.issues', []);

    SceneDirector::assertPromptedTimes(1);
});

it('has the scene director repair a sequence whose frames name no place, before it is reviewed', function () {
    Element::factory()->for($this->project)->place()->create(['name' => 'Quay']);
    $unplaced = quaySequence();
    $unplaced['shots'][0]['start'] = [...quayFrame(), 'place' => 'On a walkway.', 'elements' => []];
    SceneDirector::fake([$unplaced, quaySequence()]);

    tellStory($this)
        ->assertJsonPath('messages.1.sequence.shots.0.start.elements', ['Quay'])
        ->assertJsonPath('messages.1.sequence.issues', []);

    SceneDirector::assertPromptedTimes(2);
    SequenceReviewer::assertPromptedTimes(1);
    SceneDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Shot 1 names no place in its start frame'));
});

it('lays out the shots in the background and shows that on the turn until the sequence is there', function () {
    Queue::fake([DirectSequence::class]);
    SceneDirector::fake([quaySequence()]);

    tellStory($this)
        ->assertJsonPath('messages.1.directing', true)
        ->assertJsonPath('messages.1.story', REVIEW_STORY)
        ->assertJsonPath('reload', true)
        ->assertJsonMissingPath('messages.1.sequence');

    actingAs($this->director, 'director')
        ->postJson(route('public.shots.plan.sequence', [$this->project, $this->shot]), ['turn' => 1, 'accept' => true])
        ->assertUnprocessable();

    Queue::assertPushed(DirectSequence::class, fn(DirectSequence $job) => $job->shot->is($this->shot) && $job->turn === 1 && $job->leftOut === []);

    (new DirectSequence($this->shot->fresh(), 1))->handle();

    $turn = $this->shot->fresh()->plan_chat[1];

    expect($turn)->not->toHaveKey('directing')
        ->and($turn['sequence']['title'])->toBe('No photo');
});

it('tells why there is no sequence when laying out the shots fails', function () {
    Queue::fake([DirectSequence::class]);
    SceneDirector::fake(fn() => throw new RuntimeException('The director is down.'));

    tellStory($this);
    (new DirectSequence($this->shot->fresh(), 1))->handle();

    $turn = $this->shot->fresh()->plan_chat[1];

    expect($turn)->not->toHaveKey('directing')
        ->not->toHaveKey('sequence')
        ->and($turn['text'])->toContain('I could not lay out the shots right now.');
});
