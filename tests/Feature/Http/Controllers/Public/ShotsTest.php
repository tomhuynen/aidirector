<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Enums\ShotStatus;
use App\Jobs\GenerateStoryline;
use App\Models\Director;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A shot whose plan is talked about in the plan chat.
 *
 * @param  array<string, mixed>  $attributes
 */
function planShot(Project $project, array $attributes = []): Shot
{
    return Shot::factory()->for($project)->create(['status' => ShotStatus::STORYLINE_READY, 'storyline' => ['keyframes' => []], ...$attributes]);
}

/**
 * A reply of the plan director: by default it only talks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function directorReply(array $overrides = []): array
{
    return ['reply' => 'Fine.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'shots' => [], 'proposal' => null, ...$overrides];
}

/**
 * A plan the director agreed on in the chat.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agreedPlan(array $overrides = []): array
{
    return [
        'takeaway' => 'Wear your badge on your chest',
        'kind' => 'scene',
        'storyline' => 'He walks up and holds out the badge.',
        'keyframes' => [['title' => 'Arrives', 'description' => 'He walks up to the counter.', 'spatial' => '', 'elements' => []]],
        'seconds' => 3,
        'setting_from' => null,
        ...$overrides,
    ];
}

function talkTo(mixed $test, Shot $shot, string $message): Illuminate\Testing\TestResponse
{
    return actingAs($test->director, 'director')
        ->postJson(route('public.shots.plan.chat', [$shot->project_id === $test->project->id ? $test->project : $shot->project, $shot]), ['message' => $message])
        ->assertOk();
}

describe('create', function () {
    it('adds a new shot at the end of the sequence, empty, to work out in the plan chat', function () {
        Shot::factory()->for($this->project)->create(['position' => 1]);

        $response = actingAs($this->director, 'director')->post(route('public.shots.store', $this->project))->assertSessionHasNoErrors();

        $shot = Shot::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('public.shots.view', [$this->project, $shot]));
        expect($shot->position)->toBe(2)
            ->and($shot->takeaway)->toBe('')
            ->and($shot->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->storylineKeyframes())->toBe([]);
        Queue::assertNotPushed(GenerateStoryline::class);
    });

    it('opens the page that makes the new shot', function () {
        actingAs($this->director, 'director')
            ->get(route('public.shots.create', $this->project))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('shots/create')->has('siblings', 0));
    });

    it('forbids adding shots to another director\'s project', function () {
        $other = Project::factory()->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.store', $other))
            ->assertForbidden();
    });

    it('puts the takeaway agreed in the chat into the shot, and keeps it when a later plan has none', function () {
        Queue::fake();
        PlanDirector::fake([
            directorReply(['proposal' => agreedPlan(['takeaway' => 'Wear your badge visibly on site'])]),
            directorReply(['proposal' => agreedPlan(['takeaway' => ''])]),
        ]);
        $shot = planShot($this->project, ['takeaway' => '', 'title' => '']);

        talkTo($this, $shot, 'yes, write it');

        expect($shot->fresh())->takeaway->toBe('Wear your badge visibly on site')->title->toBe('Wear your badge visibly on site');

        $shot->fresh()->forceFill(['status' => ShotStatus::STORYLINE_READY])->save();
        talkTo($this, $shot, 'make it shorter');

        expect($shot->fresh()->takeaway)->toBe('Wear your badge visibly on site');
    });

    it('keeps the framing size the plan chat agreed, and asks for coverage and a story', function () {
        Queue::fake();
        PlanDirector::fake([
            directorReply(['proposal' => agreedPlan(['framing' => 'medium'])]),
            directorReply(['proposal' => agreedPlan(['storyline' => 'Shorter.', 'framing' => 'zoomed'])]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'yes, write it');

        expect($shot->fresh()->storylineFraming()['size'])->toBe('medium')
            ->and($shot->fresh()->shotSize())->toBe(App\Enums\ShotSize::MEDIUM);

        $shot->fresh()->forceFill(['status' => ShotStatus::STORYLINE_READY])->save();
        talkTo($this, $shot, 'make it shorter');

        expect($shot->fresh()->storylineFraming()['size'])->toBe('medium');

        PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Coverage: think like a film editor, not like a slide')
            && str_contains((string) $prompt->agent->instructions(), 'Give it a turn')
            && str_contains((string) $prompt->agent->instructions(), 'A strong plan for the same takeaway')
            && str_contains((string) $prompt->agent->instructions(), 'The idea is already a small story'));
    });

    it('keeps up to three short answers to click under a reply, and leaves out empty or long ones', function () {
        PlanDirector::fake([directorReply([
            'reply' => 'Shall I draw the places?',
            'answers' => ['Yes, draw the places', ' ', 'Yes, draw the places', 'Change the storyline', str_repeat('a', 61), 'Keep it', 'One more'],
        ])]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'the plan is fine')
            ->assertJsonPath('messages.1.answers', ['Yes, draw the places', 'Change the storyline', 'Keep it']);
    });

    it('talks first, then puts the agreed plan into the shot, shows the overview and draws it once the director agrees', function () {
        Queue::fake();
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        PlanDirector::fake([
            directorReply(['reply' => 'Should the camera stay in front of her?', 'cast' => ['Female engineer']]),
            directorReply(['reply' => 'The plan is written.', 'proposal' => agreedPlan([
                'kind' => 'close-up',
                'storyline' => 'She pulls the cord and the badge comes round to her chest.',
                'keyframes' => [['title' => 'Badge Behind', 'description' => 'The Female engineer, the cord over her shoulder.', 'spatial' => 'Only the cord shows on her chest.', 'elements' => ['female engineer']]],
            ])]),
            directorReply(['reply' => 'Drawing it now.', 'draw' => true]),
        ]);
        $shot = planShot($this->project, [
            'chosen_storyline' => ['title' => 'Badge', 'storyline' => 'She wears her badge.'],
            'storyline' => ['keyframes' => [['title' => 'Badge', 'description' => 'She wears her badge.', 'elements' => ['Female engineer']]]],
        ]);

        talkTo($this, $shot, 'the badge starts on her back')
            ->assertJsonPath('messages.0', ['role' => 'director', 'text' => 'the badge starts on her back'])
            ->assertJsonPath('messages.1.cast', [$engineer->sqid])
            ->assertJsonMissingPath('messages.1.proposal')
            ->assertJsonPath('reload', false);

        talkTo($this, $shot, 'yes, in front of her')
            ->assertJsonPath('messages.3.proposal', ['kind' => 'close-up', 'settingFrom' => null])
            ->assertJsonPath('messages.3.overview.0.beats', ['Badge Behind'])
            ->assertJsonPath('reload', true);

        // Nothing is drawn before the director agrees on the overview.
        expect($shot->fresh()->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->fresh()->awaitsGo())->toBeTrue();
        Queue::assertNotPushed(App\Jobs\GenerateKeyframes::class);

        // Drawing starts: the page reloads to show the keyframes being drawn.
        talkTo($this, $shot->fresh(), 'draw it')->assertJsonPath('reload', true);

        $shot->refresh();

        expect($shot->kind)->toBe(App\Enums\ShotKind::CLOSE_UP)
            ->and($shot->chosenStoryline()['storyline'])->toBe('She pulls the cord and the badge comes round to her chest.')
            ->and($shot->storylineKeyframes())->toEqual([['title' => 'Badge Behind', 'description' => 'The Female engineer, the cord over her shoulder.', 'spatial' => 'Only the cord shows on her chest.', 'elements' => ['Female engineer'], 'handled' => []]])
            ->and($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->awaitsGo())->toBeFalse()
            ->and($shot->plan_chat)->toHaveCount(6);
        Queue::assertPushed(App\Jobs\GenerateKeyframes::class);
        // The conversation and the plan as saved are sent along every turn; nothing becomes a rule.
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Director: the badge starts on her back')
            && str_contains($prompt->prompt, 'You: Should the camera stay in front of her?')
            && str_contains($prompt->prompt, '1. Badge: She wears her badge. (cast and sets: Female engineer)')
            && str_contains((string) $prompt->agent->instructions(), 'There are no separate rules for a shot'));
    });

    it('builds the shot up step by step and offers to make what the cast and sets miss', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'There is no badge clip yet; shall I make one?', 'stage' => 'cast', 'new_elements' => [['name' => 'Badge clip', 'type' => 'object', 'description' => 'A small metal clip on a short red cord.']]]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'yes, those keyframes work')
            ->assertJsonPath('messages.1.stage', 'cast')
            ->assertJsonPath('reload', true);

        expect($shot->fresh()->storyline['new_elements'])->toBe([['name' => 'Badge clip', 'type' => 'object', 'description' => 'A small metal clip on a short red cord.']]);
        PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), '- close-up: when'));
    });

    it('shows the cast under a reply only when it changed since it was last shown', function () {
        $visitor = App\Models\Element::factory()->for($this->project)->create(['name' => 'Helmeted visitor', 'type' => 'person']);
        $bin = App\Models\Element::factory()->for($this->project)->create(['name' => 'Burning waste bin', 'type' => 'object']);
        PlanDirector::fake([
            directorReply(['reply' => 'I use these.', 'stage' => 'cast', 'cast' => ['Helmeted visitor', 'Burning waste bin']]),
            directorReply(['reply' => 'Writing the plan.', 'stage' => 'cast', 'cast' => ['Burning waste bin', 'Helmeted visitor']]),
            directorReply(['reply' => 'Only her now.', 'stage' => 'cast', 'cast' => ['Helmeted visitor']]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'ok');
        talkTo($this, $shot->fresh(), 'ok');
        talkTo($this, $shot->fresh(), 'leave out the bin');

        $replies = collect($shot->fresh()->plan_chat)->where('role', 'assistant')->values();

        expect($replies[$replies->count() - 3]['cast'])->toEqualCanonicalizing([$visitor->sqid, $bin->sqid])
            ->and($replies[$replies->count() - 2])->not->toHaveKey('cast')
            ->and($replies[$replies->count() - 1]['cast'])->toBe([$visitor->sqid]);
    });

    it('shows the cast made while the director was answering, such as a truck made from an offer', function () {
        $visitor = App\Models\Element::factory()->for($this->project)->create(['name' => 'Helmeted visitor', 'type' => 'person']);
        $truck = null;
        PlanDirector::fake(function () use (&$truck) {
            // Made from its offer in another request while this answer is on its way.
            $truck = App\Models\Element::factory()->for($this->project)->create(['name' => 'Visitor delivery truck', 'type' => 'object']);

            return directorReply(['reply' => 'I use these.', 'stage' => 'cast', 'cast' => ['Visitor delivery truck', 'Helmeted visitor']]);
        });
        $shot = planShot($this->project);

        talkTo($this, $shot, 'ok');

        expect(collect($shot->fresh()->plan_chat)->where('role', 'assistant')->last()['cast'])->toEqualCanonicalizing([$visitor->sqid, $truck->sqid]);
    });

    it('asks again when an idea is proposed without checking its story and places', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'The host guides her away from the fire.', 'stage' => 'idea', 'rules_check' => ['places' => 'none', 'must_read' => 'none', 'rules' => [], 'story' => 'none']]),
            directorReply(['reply' => 'She lowers her phone at the smoke and walks to the exit herself; the call is a second shot.', 'stage' => 'idea', 'rules_check' => ['places' => 'the corridor', 'must_read' => 'none', 'rules' => [1, 7], 'story' => 'about to call beside the smoke; she walks to the exit herself.']]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'ok');

        PlanDirector::assertPromptedTimes(2);
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Your previous answer to this message proposed an idea or keyframes with story or places in rules_check left empty'));
        expect(collect($shot->fresh()->plan_chat)->last()['text'])->toBe('She lowers her phone at the smoke and walks to the exit herself; the call is a second shot.')
            ->and(collect($shot->fresh()->plan_chat)->last()['rules_check']['rules'])->toBe([1, 7])
            ->and($shot->generations()->count())->toBe(2);
    });

    it('does not ask again when the idea was checked or nothing is proposed', function (array $reply) {
        PlanDirector::fake([directorReply($reply)]);

        talkTo($this, planShot($this->project), 'ok');

        PlanDirector::assertPromptedTimes(1);
    })->with([
        'checked idea' => [['stage' => 'idea', 'rules_check' => ['places' => 'the corridor', 'must_read' => 'none', 'rules' => [], 'story' => 'about to call beside the smoke; she walks away.']]],
        'takeaway step' => [['stage' => 'takeaway', 'rules_check' => ['places' => '', 'must_read' => 'none', 'rules' => [], 'story' => 'none']]],
    ]);

    it('asks again when the shots it proposes break the sequence rules', function () {
        $checked = ['places' => 'beside the bin, behind the line', 'must_read' => 'none', 'rules' => [], 'story' => 'too close to the fire; the officer points him back.'];
        PlanDirector::fake([
            directorReply(['reply' => 'Three shots: too close and pointed back, at a distance, the phone.', 'stage' => 'idea', 'rules_check' => [...$checked, 'sequence' => [
                ['framing' => 'full', 'steps' => 5, 'adds' => 'too close, stopped, pointed back'],
                ['framing' => 'full', 'steps' => 1, 'adds' => 'at a distance'],
                ['framing' => 'close-up', 'steps' => 2, 'adds' => 'the call'],
            ]]]),
            directorReply(['reply' => 'Four shots: the burning bin, him freezing, stepping back behind the line, the call.', 'stage' => 'idea', 'rules_check' => [...$checked, 'sequence' => [
                ['framing' => 'close-up', 'steps' => 1, 'adds' => 'the danger'],
                ['framing' => 'medium', 'steps' => 2, 'adds' => 'he freezes with the phone'],
                ['framing' => 'full', 'steps' => 3, 'adds' => 'pointed back behind the line'],
                ['framing' => 'close-up', 'steps' => 2, 'adds' => 'the call'],
            ]]]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'what shots?');

        PlanDirector::assertPromptedTimes(2);
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Shot 1 has 5 steps; split it, no shot has more than 3.')
            && str_contains($prompt->prompt, 'Shots 1 and 2 are both full;'));
        expect(collect($shot->fresh()->plan_chat)->last()['text'])->toStartWith('Four shots')
            ->and(collect($shot->fresh()->plan_chat)->last()['rules_check']['sequence'])->toHaveCount(4);
    });

    it('finds nothing to rework in a sequence that keeps to the rules, or in a single shot', function () {
        $sequence = fn(array ...$shots) => ['rules_check' => ['sequence' => array_map(fn(array $shot) => ['framing' => $shot[0], 'steps' => $shot[1], 'adds' => 'something'], $shots)]];

        expect(PlanDirector::sequenceProblems($sequence(['close-up', 1], ['medium', 3], ['full', 2])))->toBe([])
            ->and(PlanDirector::sequenceProblems($sequence(['full', 6])))->toBe([])
            ->and(PlanDirector::sequenceProblems(['rules_check' => []]))->toBe([])
            ->and((string) (new PlanDirector(planShot($this->project), []))->instructions())->toContain('no two shots in a row have the same framing');
    });

    it('tells the story in several shots right after this one, grouped together', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'Then this shot is the arrival; the others wait right after it.', 'stage' => 'kind', 'shots' => [
                ['takeaway' => 'Report a damaged badge at reception', 'kind' => 'scene', 'idea' => 'A visitor walks up to the counter.', 'setting' => 'new_place', 'same_place_as' => 0],
                ['takeaway' => 'Hand the damaged badge over', 'kind' => 'close-up', 'idea' => 'Hands slide the cracked badge across the counter.', 'setting' => 'continues', 'same_place_as' => 0],
                ['takeaway' => 'Get a working badge back', 'kind' => 'close-up', 'idea' => 'The receptionist hands over a new badge.', 'setting' => 'continues', 'same_place_as' => 0],
            ]]),
            directorReply(['reply' => 'Shall the receptionist slide it over?', 'stage' => 'idea']),
        ]);
        $before = Shot::factory()->for($this->project)->create(['position' => 1]);
        $shot = planShot($this->project, ['position' => 2]);
        $after = Shot::factory()->for($this->project)->create(['position' => 3]);

        talkTo($this, $shot, 'yes, those three shots')->assertJsonPath('reload', true);

        $shots = $this->project->shots()->get();
        $group = $shot->fresh()->group_key;

        expect($shots->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($shots->pluck('id')->all())->toBe([$before->id, $shot->id, $shots[2]->id, $shots[3]->id, $after->id])
            ->and($group)->not->toBeNull()
            ->and($shots->slice(1, 3)->pluck('group_key')->unique()->all())->toBe([$group])
            ->and($shot->fresh()->takeaway)->toBe('Report a damaged badge at reception')
            ->and($shot->fresh()->notes)->toBe('A visitor walks up to the counter.')
            ->and($shots[2]->takeaway)->toBe('Hand the damaged badge over')
            ->and($shots[2]->kind)->toBe(App\Enums\ShotKind::CLOSE_UP)
            ->and($shots[2]->status)->toBe(ShotStatus::STORYLINE_READY)
            ->and($shots[2]->plan_chat[0]['text'])->toContain('Hands slide the cracked badge across the counter.');

        // The next shot's chat knows the whole sequence and where each shot plays.
        talkTo($this, $shots[2], 'yes');

        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '1. SH020: A visitor walks up to the counter. (scene)')
            && str_contains($prompt->prompt, '2. SH030: Hands slide the cracked badge across the counter. (close-up, continuing from SH020) <- this shot')
            && str_contains((string) $prompt->agent->instructions(), 'what another shot of the sequence shows never happens in this one'));
    });

    it('updates the sequence when it is agreed again, instead of adding it twice', function () {
        $sequence = fn(string $second) => directorReply(['shots' => [
            ['takeaway' => 'Ask before you take a photo', 'kind' => 'scene', 'idea' => 'The host stops the visitor at the dock.', 'setting' => 'new_place', 'same_place_as' => 0],
            ['takeaway' => 'Photograph only where allowed', 'kind' => 'close-up', 'idea' => $second, 'setting' => 'continues', 'same_place_as' => 0],
        ]]);
        PlanDirector::fake([$sequence('The host points to a safe spot.'), $sequence('The host points away from the dock.')]);
        $shot = planShot($this->project, ['position' => 1]);

        talkTo($this, $shot, 'ok');
        talkTo($this, $shot->fresh(), 'perfect');

        $shots = $this->project->shots()->get();

        expect($shots)->toHaveCount(2)
            ->and($shots[1]->notes)->toBe('The host points away from the dock.')
            ->and($shots[1]->plan_chat)->toHaveCount(1)
            ->and($shots[1]->plan_chat[0]['text'])->toContain('The host points away from the dock.');
    });

    it('removes only untouched shots of the sequence the director agreed to drop', function () {
        $shot = planShot($this->project, ['position' => 1, 'group_key' => 'g1']);
        $talked = planShot($this->project, ['position' => 2, 'group_key' => 'g1', 'plan_chat' => [['role' => 'assistant', 'text' => 'Idea'], ['role' => 'director', 'text' => 'yes']]]);
        $duplicate = planShot($this->project, ['position' => 3, 'group_key' => 'g1', 'plan_chat' => [['role' => 'assistant', 'text' => 'Idea']]]);
        $elsewhere = planShot($this->project, ['position' => 4]);
        PlanDirector::fake([directorReply(['reply' => 'Removed.', 'remove_shots' => ['SH020', 'SH030', 'SH040']])]);

        talkTo($this, $shot, 'yes, remove them')->assertJsonPath('reload', true);

        expect(Shot::query()->find($duplicate->id))->toBeNull()
            ->and($shot->fresh())->not->toBeNull()
            ->and($talked->fresh())->not->toBeNull()
            ->and($elsewhere->fresh()->position)->toBe(3)
            ->and($this->project->shots()->pluck('position')->all())->toBe([1, 2, 3]);
    });

    it('removes this shot when the director drops it before anything is drawn and goes on to the next', function () {
        $shot = planShot($this->project, ['position' => 1]);
        $next = planShot($this->project, ['position' => 2]);
        PlanDirector::fake([directorReply(['reply' => 'I\'ll remove SH010.', 'remove_shots' => ['SH010']])]);

        talkTo($this, $shot, 'the full scene was enough, delete this part')
            ->assertJsonPath('visit', route('public.shots.view', [$this->project, $next]));

        expect(Shot::query()->find($shot->id))->toBeNull()
            ->and($next->fresh()->position)->toBe(1);
    });

    it('keeps this shot once its keyframes are drawn', function () {
        $shot = planShot($this->project, ['position' => 1]);
        Keyframe::factory()->for($shot)->create();
        PlanDirector::fake([directorReply(['reply' => 'Removed.', 'remove_shots' => ['SH010']])]);

        talkTo($this, $shot, 'delete this shot')->assertJsonMissingPath('visit');

        expect($shot->fresh())->not->toBeNull();
    });

    it('offers to make a new person when the director asks for one', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'Shall I make him?', 'stage' => 'cast', 'new_elements' => [['name' => 'Male visitor', 'type' => 'person', 'description' => 'A man in his forties in a grey jacket, with no lanyard or badge.']]]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'make another male visitor without a badge');

        expect($shot->fresh()->storyline['new_elements'][0]['type'])->toBe('person');
    });

    it('drops an offer to make cast and sets once the conversation moves on', function () {
        PlanDirector::fake([directorReply(['reply' => 'What did not work?'])]);
        $shot = planShot($this->project, ['storyline' => ['keyframes' => [], 'new_elements' => [['name' => 'Permit holder', 'type' => 'object', 'description' => 'A clear sleeve.']]]]);

        talkTo($this, $shot, 'lets start over')->assertJsonPath('reload', true);

        expect($shot->fresh()->storyline)->not->toHaveKey('new_elements');
    });

    it('keeps the cast and sets made from the chat with the message', function () {
        $badge = Element::factory()->for($this->project)->create(['name' => 'Broken badge holder']);
        PlanDirector::fake([directorReply(['reply' => 'I will use it.'])]);
        $shot = planShot($this->project);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.chat', [$this->project, $shot]), ['message' => 'I added Broken badge holder to the cast and sets.', 'made' => [$badge->sqid, 'gone']])
            ->assertOk()
            ->assertJsonPath('messages.0.made', [$badge->sqid]);
    });

    it('has the picture of an item in the cast and sets redrawn when asked in the chat', function () {
        Queue::fake();
        $tray = Element::factory()->for($this->project)->create(['name' => 'Red report tray']);
        PlanDirector::fake([
            directorReply(['reply' => 'I am redrawing the tray empty.', 'adjust_elements' => [['name' => 'red report tray', 'change' => 'Make the tray empty.'], ['name' => 'Unknown thing', 'change' => 'Make it blue.']]]),
            directorReply(['reply' => 'It is being redrawn.', 'adjust_elements' => [['name' => 'Red report tray', 'change' => 'Empty it.']]]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'the tray should be empty')
            ->assertJsonPath('messages.1.adjusted', [$tray->sqid])
            ->assertJsonPath('reload', true);

        expect($tray->fresh()->rendering)->toBeTrue();
        Queue::assertPushed(App\Jobs\UpdateElementImage::class, fn($job) => $job->element->is($tray) && $job->instruction === 'Make the tray empty.');

        // Asked again while it is still drawn: shown with its loader, not drawn twice.
        talkTo($this, $shot, 'it still has an item in it')->assertJsonPath('messages.3.adjusted', [$tray->sqid]);

        Queue::assertPushed(App\Jobs\UpdateElementImage::class, 1);
    });

    it('times the shot for the agreed plan and writes the voice-over again', function () {
        Queue::fake();
        PlanDirector::fake([directorReply(['proposal' => agreedPlan(['seconds' => 5])])]);
        $shot = planShot($this->project, [
            'duration' => 8,
            'voice_over' => 'An old text for nine seconds.',
            'chosen_storyline' => ['title' => 'Old', 'storyline' => 'A long plan.'],
            'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 9], 'keyframes' => [['title' => 'Old', 'description' => 'A long plan.']]],
        ]);

        talkTo($this, $shot, 'yes');

        expect($shot->fresh())
            ->durationInSeconds()->toBe(5)
            ->voice_over->toBeNull()
            ->and($shot->fresh()->storylineFraming())->toBe(['spot' => 'The counter.', 'size' => null, 'seconds' => 5]);
        Queue::assertPushed(App\Jobs\GenerateVoiceOver::class);
    });

    it('links the cast and sets a plan names by their exact names, leaving out what the project does not have', function () {
        Queue::fake();
        Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        Element::factory()->for($this->project)->place()->create(['name' => 'Indoor Assembly Hall']);
        PlanDirector::fake([directorReply(['proposal' => agreedPlan(['keyframes' => [
            ['title' => 'At the sign', 'description' => 'She looks at the sign.', 'spatial' => '', 'elements' => ['female engineer', 'INDOOR ASSEMBLY HALL', 'A crane']],
            ['title' => 'Empty', 'description' => 'Only the hall.', 'spatial' => '', 'elements' => ['Indoor Assembly Hall']],
        ]])])]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'yes');

        expect(array_column($shot->fresh()->storylineKeyframes(), 'elements'))->toBe([['Female engineer', 'Indoor Assembly Hall'], ['Indoor Assembly Hall']]);
    });

    it('waits to draw until new cast pictures are ready, then draws by itself', function () {
        Queue::fake();
        $clip = Element::factory()->for($this->project)->create(['name' => 'Badge clip', 'rendering' => true]);
        PlanDirector::fake([
            directorReply(['proposal' => agreedPlan(['keyframes' => [['title' => 'Clip', 'description' => 'The Badge clip on the vest.', 'spatial' => '', 'elements' => ['Badge clip']]]])]),
            directorReply(['draw' => true]),
        ]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'yes');
        talkTo($this, $shot->fresh(), 'draw it');

        expect($shot->fresh())->status->toBe(ShotStatus::STORYLINE_READY)
            ->and($shot->fresh()->waitsFor())->toBe('the pictures of the new cast and sets');
        Queue::assertNotPushed(App\Jobs\GenerateKeyframes::class);

        $clip->forceFill(['rendering' => false])->save();
        Shot::drawWaitingShots($this->project->id);

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
        Queue::assertPushed(App\Jobs\GenerateKeyframes::class);
    });
});

describe('view', function () {
    it('shows the shot workspace with its siblings', function () {
        $shots = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shots->first()]))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('shots/view')->has('siblings', 3)->where('siblings.0.duration', 5));
    });

    it('marks the shots in the list that are being generated', function () {
        $idle = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::VIDEO_READY]);
        $rendering = Shot::factory()->for($this->project)->create(['position' => 2, 'status' => ShotStatus::VIDEO_PENDING, 'video_submitted_at' => now()]);
        $drawing = Shot::factory()->for($this->project)->create(['position' => 3, 'status' => ShotStatus::KEYFRAMES_READY]);
        $keyframe = Keyframe::factory()->for($drawing)->create(['position' => 1, 'rendering' => true]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $idle]))
            ->assertInertia(fn($page) => $page
                ->where('siblings.0.busy', false)
                ->where('siblings.0.render', null)
                ->where('siblings.1.busy', true)
                ->where('siblings.1.render.key', "video:shot:{$rendering->sqid}")
                ->where('siblings.1.render.startedAt', $rendering->video_submitted_at->toIso8601String())
                ->where('siblings.2.busy', true)
                ->where('siblings.2.render.key', "keyframe:{$keyframe->sqid}"));
    });

    it('does not resolve a shot through a project it does not belong to', function () {
        $otherProject = Project::factory()->ownedBy($this->director)->create();
        $shot = Shot::factory()->for($otherProject)->create();

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertNotFound();
    });
});

describe('reorder and destroy', function () {
    it('reorders the shots of a project', function () {
        [$first, $second, $third] = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['shots' => [$third->sqid, $first->sqid, $second->sqid]])
            ->assertRedirect(route('public.projects.view', $this->project));

        expect($third->fresh()->position)->toBe(1)
            ->and($first->fresh()->position)->toBe(2)
            ->and($second->fresh()->position)->toBe(3);
    });

    it('rejects an order that does not cover every shot', function () {
        [$first] = Shot::factory()->for($this->project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['shots' => [$first->sqid]])
            ->assertSessionHasErrors('shots');
    });

    it('destroys a shot and closes the gap', function () {
        [$first, $second, $third] = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $second]))
            ->assertRedirect(route('public.shots.view', [$this->project, $third]));

        // Kept, with what the system can learn from it, but no longer in the film.
        $this->assertSoftDeleted($second);
        expect($first->fresh()->position)->toBe(1)
            ->and($third->fresh()->position)->toBe(2);
    });

    it('stays in the editor on the shot before when the last one is deleted, or a new shot when none are left', function () {
        [$first, $second] = Shot::factory()->for($this->project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $second]))
            ->assertRedirect(route('public.shots.view', [$this->project, $first]));

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $first]))
            ->assertRedirect(route('public.shots.create', $this->project));
    });
});
