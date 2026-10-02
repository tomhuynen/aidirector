<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineOptionsWriter;
use App\Ai\Agents\StorylineWriter;
use App\Enums\ProjectPurpose;
use App\Enums\ShotSize;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function suggestedStorylines(): array
{
    return [
        ['title' => 'Straightforward', 'storyline' => 'The man walks to the mailbox and posts the envelope. He gives a thumbs up.'],
        ['title' => 'Hesitation', 'storyline' => 'The man pauses at the mailbox, reads the envelope once more, then posts it.'],
        ['title' => 'Relief', 'storyline' => 'The man posts the envelope and exhales, visibly relieved.'],
    ];
}

describe('suggest', function () {
    it('asks for new suggestions using the director feedback', function () {
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_READY,
            'storyline_options' => suggestedStorylines(),
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.suggest', [$this->project, $shot]), ['feedback' => 'Less dramatic'])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh()->status)->toBe(ShotStatus::OPTIONS_PENDING);

        Queue::assertPushed(GenerateStorylineOptions::class, fn(GenerateStorylineOptions $job) => $job->shot->is($shot) && $job->feedback === 'Less dramatic');
    });

    it('forbids suggesting storylines for another director\'s shot', function () {
        $other = Project::factory()->create();
        $shot = Shot::factory()->for($other)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.suggest', [$other, $shot]))
            ->assertForbidden();

        Queue::assertNothingPushed();
    });
});

describe('choose', function () {
    it('saves the chosen storyline, names the shot after it and starts planning its keyframes', function () {
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_READY,
            'storyline_options' => suggestedStorylines(),
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.choose', [$this->project, $shot]), ['option' => 1])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $shot->refresh();

        expect($shot->chosenStoryline())->toBe(suggestedStorylines()[1])
            ->and($shot->title)->toBe('Hesitation')
            ->and($shot->status)->toBe(ShotStatus::STORYLINE_PENDING)
            ->and($shot->storyline)->toBeNull();

        Queue::assertPushed(GenerateStoryline::class, fn(GenerateStoryline $job) => $job->shot->is($shot));
    });

    it('rejects an option that was not suggested', function () {
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_READY,
            'storyline_options' => suggestedStorylines(),
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.choose', [$this->project, $shot]), ['option' => 5])
            ->assertSessionHasErrors('option');

        expect($shot->fresh()->status)->toBe(ShotStatus::OPTIONS_READY);
        Queue::assertNotPushed(GenerateStoryline::class);
    });

    it('rejects choosing before any storylines were suggested', function () {
        $shot = Shot::factory()->for($this->project)->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.choose', [$this->project, $shot]), ['option' => 0])
            ->assertSessionHasErrors('option');
    });
});

describe('jobs', function () {
    it('stores the suggested storylines on the shot', function () {
        StorylineOptionsWriter::fake([['options' => suggestedStorylines()]]);

        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_PENDING]);

        (new GenerateStorylineOptions($shot))->handle();

        $shot->refresh();

        expect($shot->storylineOptions())->toBe(suggestedStorylines())
            ->and($shot->status)->toBe(ShotStatus::OPTIONS_READY)
            ->and($shot->storyline_error)->toBeNull()
            ->and($shot->generations()->where('kind', 'text')->count())->toBe(1);
    });

    it('asks OpenRouter for the configured reasoning effort', function () {
        Config::set('pipeline.reasoning_effort.storyline_options_writer', 'low');

        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'openai/gpt-5.5',
            'choices' => [['message' => ['content' => json_encode(['options' => suggestedStorylines()])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ])]);

        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_PENDING]);

        (new GenerateStorylineOptions($shot))->handle();

        expect($shot->fresh()->storylineOptions())->toBe(suggestedStorylines());

        Http::assertSent(fn(Request $request) => $request['reasoning'] === ['effort' => 'low']);
    });

    it('leaves the reasoning effort to the model when none is configured', function () {
        Config::set('pipeline.reasoning_effort.storyline_options_writer', null);

        expect((new StorylineOptionsWriter(Shot::factory()->for($this->project)->create()))->providerOptions('openrouter'))->toBe([]);
    });

    it('includes the current suggestions and feedback when asked for new ones', function () {
        StorylineOptionsWriter::fake([['options' => suggestedStorylines()]]);

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_PENDING,
            'storyline_options' => suggestedStorylines(),
        ]);

        (new GenerateStorylineOptions($shot, 'Make it funnier'))->handle();

        StorylineOptionsWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '1. Straightforward:')
            && str_contains($prompt->prompt, 'Feedback from the director: Make it funnier'));
    });

    it('asks for different interpretations of the same idea rather than takes of one scene', function () {
        $shot = Shot::factory()->for($this->project)->create();

        $instructions = (string) (new StorylineOptionsWriter($shot->load('project')))->instructions();

        expect($instructions)
            ->toContain('Each storyline is a different scene that lands the same takeaway')
            ->toContain('Change the situation, not the wording')
            ->toContain('Vary who does what')
            ->toContain('a different angle on the same scene does not count as a different storyline');
    });

    it('writes from the takeaway, the context and the cast and sets the director picked', function () {
        StorylineOptionsWriter::fake([['options' => suggestedStorylines()]]);

        $visitor = Element::factory()->for($this->project)->create();
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_PENDING,
            'takeaway' => 'Smoking is prohibited on the shipyard',
            'notes' => 'During the morning shift',
            'preferred_elements' => [$visitor->id],
        ]);

        (new GenerateStorylineOptions($shot))->handle();

        StorylineOptionsWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Takeaway: Smoking is prohibited on the shipyard')
            && str_contains($prompt->prompt, 'Context from the director: During the morning shift')
            && str_contains($prompt->prompt, '- ' . $visitor->promptLine())
            && ! str_contains($prompt->prompt, 'Subject:'));
    });

    it('lists the other shots so a new one does not repeat their scene', function () {
        Shot::factory()->for($this->project)->create(['title' => 'At the gate', 'takeaway' => 'Wear a helmet']);
        $shot = Shot::factory()->for($this->project)->create(['position' => 2]);

        expect((string) (new StorylineOptionsWriter($shot->load('project')))->instructions())
            ->toContain("Do not repeat their scene:\n- At the gate: Wear a helmet")
            ->not->toContain("- {$shot->title}: {$shot->takeaway}");
    });

    it('offers the teaching angles of e-learning projects as inspiration', function () {
        $project = Project::factory()->ownedBy($this->director)->create(['purpose' => ProjectPurpose::E_LEARNING]);
        $shot = Shot::factory()->for($project)->create();

        $instructions = (string) (new StorylineOptionsWriter($shot->load('project')))->instructions();

        expect($instructions)
            ->toContain('Correct behaviour modelled')
            ->toContain('Mistake and correction')
            ->toContain('Consequence first')
            ->toContain('Cue spotting');
    });

    it('returns to draft with an error when suggesting fails before any exist', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_PENDING]);

        (new GenerateStorylineOptions($shot))->failed(new RuntimeException('Provider down'));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::DRAFT)
            ->and($shot->storyline_error)->not->toBeNull()
            ->and($shot->generations()->whereNotNull('error')->count())->toBe(1);
    });

    it('passes the chosen storyline on to the keyframe writer and starts rendering', function () {
        StorylineWriter::fake([['framing' => ['size' => 'medium', 'spot' => 'Against the red mailbox on the pavement, a shop window behind it.', 'light' => 'dusk, low warm evening light', 'seconds' => 7], 'keyframes' => [
            ['title' => 'At the mailbox', 'description' => 'The man stands at the mailbox holding the envelope.', 'prompt' => 'A man in a navy suit stands at a red mailbox.'],
            ['title' => 'Posting', 'description' => 'The envelope slides into the slot.', 'prompt' => 'A man in a navy suit posts a white envelope.'],
            ['title' => 'Thumbs up', 'description' => 'The man gives a thumbs up.', 'prompt' => 'A man in a navy suit gives a thumbs up.'],
        ]]]);

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::STORYLINE_PENDING,
            'chosen_storyline' => suggestedStorylines()[2],
        ]);
        $stale = Keyframe::factory()->for($shot)->create();

        (new GenerateStoryline($shot))->handle();

        StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Chosen storyline (Relief): The man posts the envelope and exhales'));

        $shot->refresh();

        expect($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->storylineKeyframes()[0]['prompt'])->toBe('A man in a navy suit stands at a red mailbox.')
            ->and($shot->storylineFraming())->toBe(['size' => ShotSize::MEDIUM, 'spot' => 'Against the red mailbox on the pavement, a shop window behind it.', 'light' => 'dusk, low warm evening light', 'seconds' => 7])
            ->and($shot->durationInSeconds())->toBe(7)
            ->and(Keyframe::query()->whereKey($stale->id)->exists())->toBeFalse();

        Queue::assertPushed(GenerateKeyframes::class, fn(GenerateKeyframes $job) => $job->shot->is($shot));
    });

    it('asks the keyframe writer for an image prompt per keyframe', function () {
        $shot = Shot::factory()->for($this->project)->create();

        expect((string) (new StorylineWriter($shot->load('project')))->instructions())
            ->toContain('Prompt: a brief for an image model that renders this keyframe')
            ->toContain('the spot in the place with the context objects on it')
            ->toContain('nothing crosses or touches a figure')
            ->toContain('Never invent a separate wall, panel or backdrop in front of the place');
    });

    it('lets the keyframe writer time the shot unless the director set a length', function () {
        $shot = Shot::factory()->for($this->project)->create();

        expect((string) (new StorylineWriter($shot->load('project')))->instructions())
            ->toContain('Shot length: choose it yourself as the seconds')
            ->toContain('putting on or taking off clothing or gear: 3 to 4 seconds');

        $set = Shot::factory()->for($this->project)->create(['duration' => 9]);

        expect((string) (new StorylineWriter($set->load('project')))->instructions())
            ->toContain('Shot length: the director set 9 seconds.')
            ->not->toContain('rules of thumb');
    });

    it('renders at the director\'s length, then the planned one, then the project default', function () {
        $planned = ['framing' => ['size' => 'full', 'spot' => 'At the door.', 'light' => 'as the visual style', 'seconds' => 8], 'keyframes' => []];

        expect(Shot::factory()->for($this->project)->create(['duration' => 6, 'storyline' => $planned])->durationInSeconds())->toBe(6)
            ->and(Shot::factory()->for($this->project)->create(['storyline' => $planned])->durationInSeconds())->toBe(8)
            ->and(Shot::factory()->for($this->project)->create(['storyline' => [...$planned, 'framing' => [...$planned['framing'], 'seconds' => 40]]])->durationInSeconds())->toBe(15)
            ->and(Shot::factory()->for($this->project)->create()->durationInSeconds())->toBe($this->project->default_duration);
    });

    it('lets the keyframe writer choose the framing by what the shot has to communicate', function () {
        $shot = Shot::factory()->for($this->project)->create();

        expect((string) (new StorylineWriter($shot->load('project')))->instructions())
            ->toContain('choose one shot size for the whole shot by what it has to communicate')
            ->toContain('- close-up: when the point is what the hands do with an object')
            ->toContain('- wide: when the surroundings are the point')
            ->toContain('the shot plays at one spot inside the place')
            ->toContain('Frame the action: the people and the object they act on')
            ->toContain('never describe their appearance, such as age, build, hair, clothing or colours')
            ->toContain('never introduce a new person')
            ->toContain('Light: the time of day and light the storyline calls for')
            ->toContain('Do not list background extras such as vehicles, containers, cranes or people')
            ->toContain('sit together in the centre of the frame and take most of it');
    });

    it('asks the keyframe writer for few, readable props so the video model cannot mistake them', function () {
        $shot = Shot::factory()->for($this->project)->create();

        expect((string) (new StorylineWriter($shot->load('project')))->instructions())
            ->toContain('use as few hand-held objects as the story needs, ideally one per character')
            ->toContain('turns an unclear object into a copy of the main one')
            ->toContain('keep the hands apart and make the objects clearly different in shape and colour')
            ->toContain('which hand holds which object');
    });
});
