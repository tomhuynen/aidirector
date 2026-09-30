<?php

declare(strict_types=1);

use App\Ai\Agents\StorylineOptionsWriter;
use App\Ai\Agents\StorylineWriter;
use App\Enums\ProjectPurpose;
use App\Enums\ShotStatus;
use App\Jobs\GenerateStoryline;
use App\Jobs\GenerateStorylineOptions;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
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
    it('saves the chosen storyline and starts planning its keyframes', function () {
        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::OPTIONS_READY,
            'storyline_options' => suggestedStorylines(),
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.choose', [$this->project, $shot]), ['option' => 1])
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        $shot->refresh();

        expect($shot->chosenStoryline())->toBe(suggestedStorylines()[1])
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

    it('asks for a different teaching angle per storyline on e-learning projects', function () {
        $project = Project::factory()->ownedBy($this->director)->create(['purpose' => ProjectPurpose::E_LEARNING]);
        $shot = Shot::factory()->for($project)->create();

        $instructions = (string) (new StorylineOptionsWriter($shot->load('project')))->instructions();

        expect($instructions)
            ->toContain('no two storylines may share an angle')
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

    it('passes the chosen storyline on to the keyframe writer', function () {
        StorylineWriter::fake([['keyframes' => [
            ['title' => 'At the mailbox', 'description' => 'The man stands at the mailbox holding the envelope.'],
            ['title' => 'Posting', 'description' => 'The envelope slides into the slot.'],
            ['title' => 'Thumbs up', 'description' => 'The man gives a thumbs up.'],
        ]]]);

        $shot = Shot::factory()->for($this->project)->create([
            'status' => ShotStatus::STORYLINE_PENDING,
            'chosen_storyline' => suggestedStorylines()[2],
        ]);

        (new GenerateStoryline($shot))->handle();

        StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Chosen storyline (Relief): The man posts the envelope and exhales'));

        expect($shot->fresh()->status)->toBe(ShotStatus::STORYLINE_READY);
    });
});
