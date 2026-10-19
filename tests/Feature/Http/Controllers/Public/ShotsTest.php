<?php

declare(strict_types=1);

use App\Ai\Agents\PlanDirector;
use App\Ai\Agents\SceneDirector;
use App\Ai\Briefs\BodyRules;
use App\Enums\ElementType;
use App\Enums\KeyframeStatus;
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
    return ['reply' => 'Fine.', 'stage' => 'plan', 'cast' => [], 'new_elements' => [], 'adjust_elements' => [], 'direct' => '', 'proposal' => null, ...$overrides];
}

/**
 * One frame of a directed shot, staged.
 *
 * @return array<string, mixed>
 */
function directedFrame(string $place, string $holds = ''): array
{
    return ['title' => 'Frame', 'summary' => '', 'place' => $place, 'cast' => $holds === '' ? [] : [['name' => 'Visitor', 'stands' => '', 'faces' => '', 'looks_at' => '', 'holds' => $holds, 'hand' => 'right', 'doing' => '']], 'spatial' => '', 'elements' => []];
}

/**
 * One shot as the scene director answers it.
 *
 * @param  list<int>  $shows
 * @param  array<string, mixed>|null  $end
 * @return array<string, mixed>
 */
function directedShot(string $title, string $action, string $kind = 'scene', array $shows = [1], ?array $end = null, int $seconds = 4): array
{
    return [
        'title' => $title, 'kind' => $kind, 'purpose' => 'What the viewer must understand.', 'notice' => 'What they must notice.',
        'start' => directedFrame('At the reception desk.'), 'action' => $action, 'end' => $end ?? directedFrame('At the reception desk.', 'the cracked badge'),
        'seconds' => $seconds, 'cut' => 'The act is done.', 'shows' => $shows,
    ];
}

/**
 * The scene director's answer: a story directed into three shots, the second and third continuing the one before.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function directedSequence(array $overrides = []): array
{
    return [
        'title' => 'Cracked badge',
        'change' => 'The cracked badge becomes a new one.',
        'facts' => ['He shows the cracked badge.', 'The badge changes hands.', 'He gets a new one.'],
        'context' => ['A badge is needed to pass the gate.'],
        'must_not' => [],
        'shots' => [
            directedShot('Report it', 'He walks up to the counter and holds out the cracked badge.', shows: [1]),
            directedShot('Hand it over', 'Hands slide the cracked badge across the counter.', 'close-up', [2], directedFrame('At the reception desk.'), 2),
            directedShot('New badge back', 'The receptionist hands over a new badge.', 'close-up', [3], directedFrame('At the reception desk.', 'a new badge'), 3),
        ],
        ...$overrides,
    ];
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
        'title' => 'Badge on chest',
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
            ->and($shot->idea)->toBeNull()
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

    it('keeps the director\'s idea and suggests three storylines to pick from', function () {
        PlanDirector::fake([directorReply([
            'stage' => 'storyline',
            'confirm' => false,
            'idea' => 'A visitor shows his badge at the gate.',
            'storylines' => [
                ['title' => 'Badge at the gate', 'kind' => 'scene', 'storyline' => 'He walks up and holds out the badge.'],
                ['title' => 'Close look', 'kind' => 'unknown', 'storyline' => 'The guard looks at the badge.', 'needs' => 'a senior manager in a suit'],
                ['title' => 'Empty', 'kind' => 'scene', 'storyline' => ''],
                ['title' => 'Fourth', 'kind' => 'scene', 'storyline' => 'One too many.'],
            ],
        ])]);
        $shot = planShot($this->project, ['idea' => null, 'title' => '']);

        talkTo($this, $shot, 'a visitor shows his badge at the gate')
            ->assertJsonPath('messages.1.storylines', [
                ['title' => 'Badge at the gate', 'kind' => 'scene', 'storyline' => 'He walks up and holds out the badge.'],
                ['title' => 'Close look', 'kind' => 'scene', 'storyline' => 'The guard looks at the badge.', 'needs' => 'a senior manager in a suit'],
                ['title' => 'Fourth', 'kind' => 'scene', 'storyline' => 'One too many.'],
            ]);

        expect($shot->fresh()->idea)->toBe('A visitor shows his badge at the gate.');

        // Picked by its title only: the suggestions go along with the conversation, so the plan director knows which one.
        PlanDirector::fake([directorReply(['stage' => 'keyframes', 'reply' => '1. He walks up. 2. He holds out the badge. Shall we use these?', 'confirm' => true])]);

        talkTo($this, $shot->fresh(), 'Use “Badge at the gate”.')
            ->assertJsonPath('messages.3.confirm', true);

        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "The storylines suggested:\n1. \"Badge at the gate\" (scene): He walks up and holds out the badge."));
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Idea: (none yet: the director gives it)')
            && str_contains((string) $prompt->agent->instructions(), 'keep what they already chose in all three, such as the kind of shot or the place')
            && str_contains((string) $prompt->agent->instructions(), 'Never switch to another kind or place on your own')
            && str_contains((string) $prompt->agent->instructions(), 'set pick_place to true and suggest nothing')
            && str_contains((string) $prompt->agent->instructions(), 'a contrast or someone you would not expect')
            && str_contains((string) $prompt->agent->instructions(), 'At least one of the three turns the idea around this way')
            && str_contains((string) $prompt->agent->instructions(), 'when the director already told what happens, such as who does what and where, that is the storyline: never suggest others')
            && str_contains((string) $prompt->agent->instructions(), 'give the keyframes of step 3 in that same message'));
    });

    it('knows the documents the director shared for the project, such as the functional design', function () {
        PlanDirector::fake([directorReply()]);
        $conversationId = (string) Illuminate\Support\Str::uuid7();
        $db = Illuminate\Support\Facades\DB::connection('tenant');
        $db->table('agent_conversations')->insert(['id' => $conversationId, 'participant_type' => 'director', 'participant_id' => $this->director->id, 'title' => 'Setup', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('agent_conversation_messages')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'conversation_id' => $conversationId, 'participant_type' => 'director', 'participant_id' => $this->director->id, 'agent' => App\Ai\Agents\ProjectIntake::class, 'role' => 'user', 'content' => App\Support\Intake\DocumentText::SHARED . " \"design.txt\":\n<<<\n1. Welcome\n   Your safety is our highest priority.\n>>>", 'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '[]', 'meta' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $this->project->forceFill(['conversation_id' => $conversationId])->save();

        talkTo($this, planShot($this->project), 'give me the first item of the functional design');

        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "Shared documents:\n" . App\Support\Intake\DocumentText::SHARED . ' "design.txt"')
            && str_contains($prompt->prompt, '1. Welcome')
            && str_contains((string) $prompt->agent->instructions(), 'the first item of the functional design'));
    });

    it('lets the director choose the place from every place of the cast and sets', function () {
        PlanDirector::fake([directorReply(['stage' => 'storyline', 'reply' => 'Pick the place you want.', 'pick_place' => true])]);

        talkTo($this, planShot($this->project), 'let me choose the place')
            ->assertJsonPath('messages.1.pickPlace', true);
    });

    it('asks which of the places that fit before offering the cast, and offers only those', function () {
        $quiet = Element::factory()->for($this->project)->create(['name' => 'Quiet corridor', 'type' => ElementType::PLACE]);
        $clean = Element::factory()->for($this->project)->create(['name' => 'Clean route corridor', 'type' => ElementType::PLACE]);
        $visitor = Element::factory()->for($this->project)->create(['name' => 'Visitor', 'type' => ElementType::PERSON]);
        PlanDirector::fake([directorReply(['stage' => 'cast', 'reply' => 'Which corridor?', 'pick_place' => true, 'places' => ['Quiet corridor', 'clean route corridor', 'Visitor']])]);

        talkTo($this, planShot($this->project), 'i want another corridor')
            ->assertJsonPath('messages.1.pickPlace', true)
            ->assertJsonPath('messages.1.places', fn(array $ids) => collect($ids)->sort()->values()->all() === collect([$quiet->sqid, $clean->sqid])->sort()->values()->all());

        PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'Choosing the place comes before offering the cast')
            && str_contains((string) $prompt->agent->instructions(), 'offer to make a new one only when none of them fits'));
        expect($visitor->sqid)->not->toBeIn($this->project->shots()->first()->plan_chat[1]['places']);
    });

    it('lets the director only offer the places of the cast and sets, by their exact names', function () {
        Element::factory()->for($this->project)->create(['name' => 'Quiet corridor', 'type' => ElementType::PLACE]);
        Element::factory()->for($this->project)->create(['name' => 'Visitor', 'type' => ElementType::PERSON]);
        $schema = (new PlanDirector(planShot($this->project), []))->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory());

        expect($schema['places']->toArray()['items']['enum'])->toBe(['Quiet corridor'])
            ->and((string) (new PlanDirector(planShot($this->project), []))->instructions())->toContain('leave places empty only when the director asks to see every place');
    });

    it('suggests a new voice-over and uses it only once the director accepts it', function () {
        PlanDirector::fake([directorReply(['reply' => 'Shorter and warmer. Shall I use it?', 'confirm' => true, 'voice_over' => 'Welcome to Damen Naval. Your safety comes first.'])]);
        $shot = planShot($this->project, ['duration' => 5, 'voice_over' => 'Welcome. Safety is our highest priority here at the yard.']);

        talkTo($this, $shot, 'make the voice-over warmer')
            ->assertJsonPath('messages.1.voiceOver', ['text' => 'Welcome to Damen Naval. Your safety comes first.'])
            ->assertJsonMissingPath('messages.1.confirm');

        expect($shot->fresh()->voice_over)->toBe('Welcome. Safety is our highest priority here at the yard.');
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Voice-over: Welcome. Safety is our highest priority here at the yard. (at most 11 words for this length)'));

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.voice-over', [$this->project, $shot]), ['turn' => 1, 'accept' => true])
            ->assertOk()
            ->assertJsonPath('messages.1.voiceOver.state', 'accepted');

        expect($shot->fresh()->voice_over)->toBe('Welcome to Damen Naval. Your safety comes first.');

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.voice-over', [$this->project, $shot]), ['turn' => 1, 'accept' => false])
            ->assertJsonValidationErrors('turn');
    });

    it('keeps a longer voice-over the director asks for whole, and makes the shot long enough to speak it', function () {
        $long = 'Welcome to Damen Naval. Here we build complex ships for navies around the world. Your safety comes first, so take a moment for the rules.';
        PlanDirector::fake([directorReply(['voice_over' => $long])]);
        $shot = planShot($this->project, ['duration' => 5]);

        talkTo($this, $shot, 'make it longer, three sentences')
            ->assertJsonPath('messages.1.voiceOver', ['text' => $long, 'seconds' => 10]);

        expect($shot->fresh()->durationInSeconds())->toBe(5);
        PlanDirector::assertPrompted(fn($prompt) => str_contains((string) $prompt->agent->instructions(), 'unless the director asks for more, such as a longer text or a number of sentences'));

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.voice-over', [$this->project, $shot]), ['turn' => 1, 'accept' => true])
            ->assertOk();

        expect($shot->fresh())->voice_over->toBe($long)
            ->and($shot->fresh()->durationInSeconds())->toBe(10);
    });

    it('keeps the voice-over when the director declines the suggestion', function () {
        $shot = planShot($this->project, ['voice_over' => 'The old one.', 'plan_chat' => [['role' => 'director', 'text' => 'shorter'], ['role' => 'assistant', 'text' => 'Like this?', 'voiceOver' => ['text' => 'The new one.']]]]);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.voice-over', [$this->project, $shot]), ['turn' => 1, 'accept' => false])
            ->assertJsonPath('messages.1.voiceOver.state', 'declined');

        expect($shot->fresh()->voice_over)->toBe('The old one.');
    });

    it('puts the title agreed in the chat into the shot, and keeps it when a later plan has none', function () {
        Queue::fake();
        PlanDirector::fake([
            directorReply(['proposal' => agreedPlan(['title' => 'Badge at the gate'])]),
            directorReply(['proposal' => agreedPlan(['title' => ''])]),
        ]);
        $shot = planShot($this->project, ['title' => '']);

        talkTo($this, $shot, 'yes, write it');

        expect($shot->fresh())->title->toBe('Badge at the gate')
            ->and($shot->fresh()->chosen_storyline)->toBe(['storyline' => 'He walks up and holds out the badge.']);

        $shot->fresh()->forceFill(['status' => ShotStatus::STORYLINE_READY])->save();
        talkTo($this, $shot, 'make it shorter');

        expect($shot->fresh()->title)->toBe('Badge at the gate');
    });

    it('talks first, then puts the agreed plan into the shot and draws it', function () {
        Queue::fake();
        $engineer = Element::factory()->for($this->project)->create(['name' => 'Female engineer']);
        PlanDirector::fake([
            directorReply(['reply' => 'Should the camera stay in front of her?', 'cast' => ['Female engineer']]),
            directorReply(['reply' => 'The plan is written.', 'proposal' => agreedPlan([
                'kind' => 'close-up',
                'storyline' => 'She pulls the cord and the badge comes round to her chest.',
                'keyframes' => [['title' => 'Badge Behind', 'summary' => 'The badge hangs behind her', 'place' => 'Her chest, the cord over her shoulder', 'cast' => [['name' => 'Female engineer', 'stands' => '', 'faces' => 'camera', 'looks_at' => '', 'holds' => 'the cord', 'hand' => 'right', 'doing' => '']], 'spatial' => 'Only the cord shows on her chest.', 'elements' => ['female engineer']]],
            ])]),
        ]);
        $shot = planShot($this->project, [
            'chosen_storyline' => ['storyline' => 'She wears her badge.'],
            'storyline' => ['keyframes' => [['title' => 'Badge', 'staging' => ['summary' => 'She wears her badge', 'place' => '', 'cast' => []], 'description' => 'She wears her badge.', 'elements' => ['Female engineer']]]],
        ]);

        talkTo($this, $shot, 'the badge starts on her back')
            ->assertJsonPath('messages.0', ['role' => 'director', 'text' => 'the badge starts on her back'])
            ->assertJsonPath('messages.1.cast', [$engineer->sqid])
            ->assertJsonMissingPath('messages.1.proposal')
            ->assertJsonPath('reload', false);

        talkTo($this, $shot, 'yes, in front of her')
            ->assertJsonPath('messages.3.proposal', ['kind' => 'close-up', 'settingFrom' => null])
            ->assertJsonPath('reload', true);

        $shot->refresh();

        expect($shot->kind)->toBe(App\Enums\ShotKind::CLOSE_UP)
            ->and($shot->chosenStoryline()['storyline'])->toBe('She pulls the cord and the badge comes round to her chest.')
            ->and($shot->storylineKeyframes())->toEqual([[
                'title' => 'Badge Behind',
                'staging' => ['summary' => 'The badge hangs behind her', 'place' => 'Her chest, the cord over her shoulder', 'cast' => [['name' => 'Female engineer', 'stands' => '', 'faces' => 'camera', 'looks_at' => '', 'holds' => 'the cord', 'hand' => 'right', 'doing' => '']]],
                'description' => 'The badge hangs behind her. Her chest, the cord over her shoulder. The Female engineer faces the camera and holds the cord in the right hand.',
                'spatial' => 'Only the cord shows on her chest.',
                'elements' => ['Female engineer'],
            ]])
            ->and($shot->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shot->plan_chat)->toHaveCount(4);
        Queue::assertPushed(App\Jobs\GenerateKeyframes::class);
        // The conversation and the plan as saved are sent along every turn; nothing becomes a rule.
        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Director: the badge starts on her back')
            && str_contains($prompt->prompt, 'You: Should the camera stay in front of her?')
            && str_contains($prompt->prompt, "1. Start frame: Badge\nSummary: She wears her badge\nPlace: (none)\nSpatial fact: (none)\nCast and sets: Female engineer")
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

    it('lays out the story in shots for the director to accept, then makes them right after this one, grouped together', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'I lay out the shots for it.', 'stage' => 'kind', 'direct' => 'A visitor reports a cracked badge at the counter and gets a new one.']),
            directorReply(['reply' => 'Shall the receptionist slide it over?', 'stage' => 'idea']),
        ]);
        SceneDirector::fake([directedSequence()]);
        $before = Shot::factory()->for($this->project)->create(['position' => 1]);
        $shot = planShot($this->project, ['position' => 2]);
        $after = Shot::factory()->for($this->project)->create(['position' => 3]);

        $response = talkTo($this, $shot, 'he reports a cracked badge and gets a new one')
            ->assertJsonPath('reload', false)
            ->assertJsonPath('messages.1.sequence.change', 'The cracked badge becomes a new one.')
            ->assertJsonPath('messages.1.sequence.shots.1.kind', 'close-up')
            ->assertJsonPath('messages.1.sequence.shots.1.start.description', 'At the reception desk. The Visitor holds the cracked badge in the right hand.')
            ->assertJsonMissingPath('messages.1.sequence.state');

        expect($this->project->shots()->count())->toBe(3);
        SceneDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'The story to direct, in the director\'s words: A visitor reports a cracked badge at the counter and gets a new one.')
            && $prompt->model === config('pipeline.models.scene_director')
            && $prompt->attachments->isEmpty()
            && str_contains((string) $prompt->agent->instructions(), 'In action and the staging of the frames: ' . BodyRules::NO_TEMPO)
            && str_contains((string) $prompt->agent->instructions(), 'the decisive change')
            && str_contains((string) $prompt->agent->instructions(), 'Medium shot at eye level'));

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => 1, 'accept' => true])
            ->assertOk()
            ->assertJsonPath('reload', true)
            ->assertJsonPath('messages.1.sequence.state', 'accepted');

        $shots = $this->project->shots()->get();
        $group = $shot->fresh()->group_key;

        expect($shots->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($shots->pluck('id')->all())->toBe([$before->id, $shot->id, $shots[2]->id, $shots[3]->id, $after->id])
            ->and($group)->not->toBeNull()
            ->and($shots->slice(1, 3)->pluck('group_key')->unique()->all())->toBe([$group])
            ->and($shot->fresh()->title)->toBe('Report it')
            ->and($shot->fresh()->idea)->toBe('He walks up to the counter and holds out the cracked badge.')
            ->and($shot->fresh()->storylineKeyframes())->toHaveCount(2)
            ->and($shot->fresh()->storylineKeyframes()[1]['description'])->toBe('At the reception desk. The Visitor holds the cracked badge in the right hand.')
            ->and($shot->fresh()->durationInSeconds())->toBe(4)
            ->and($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING)
            ->and($shots[2]->title)->toBe('Hand it over')
            ->and($shots[2]->kind)->toBe(App\Enums\ShotKind::CLOSE_UP)
            ->and($shots[2]->storyline['setting_from'])->toBe(['shot_id' => $shot->id, 'keyframe' => Shot::LAST_KEYFRAME])
            ->and($shots[2]->storylineKeyframes()[0]['description'])->toBe('At the reception desk. The Visitor holds the cracked badge in the right hand.')
            ->and($shots[2]->plan_chat[0]['text'])->toContain('Hands slide the cracked badge across the counter.')
            ->and($shots[3]->storyline['setting_from'])->toBe(['shot_id' => $shots[2]->id, 'keyframe' => Shot::LAST_KEYFRAME]);

        // The accepted sequence stays answered: it is not made twice.
        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => 1, 'accept' => true])
            ->assertUnprocessable();

        // The next shot's chat knows the whole sequence and where each shot plays.
        talkTo($this, $shots[2], 'yes');

        PlanDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '1. SH020: He walks up to the counter and holds out the cracked badge. (scene)')
            && str_contains($prompt->prompt, '2. SH030: Hands slide the cracked badge across the counter. (close-up, continuing from SH020) <- this shot')
            && str_contains((string) $prompt->agent->instructions(), 'what another shot of the sequence shows never happens in this one'));
    });

    it('leaves the plan as it is when the director declines the shots', function () {
        PlanDirector::fake([directorReply(['reply' => 'I lay out the shots for it.', 'direct' => 'A visitor reports a cracked badge.'])]);
        SceneDirector::fake([directedSequence()]);
        $shot = planShot($this->project, ['title' => 'As it was']);

        talkTo($this, $shot, 'he reports a cracked badge');

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => 1, 'accept' => false])
            ->assertOk()
            ->assertJsonPath('reload', false)
            ->assertJsonPath('messages.1.sequence.state', 'declined');

        expect($this->project->shots()->count())->toBe(1)
            ->and($shot->fresh()->title)->toBe('As it was')
            ->and($shot->fresh()->group_key)->toBeNull();
    });

    it('offers the cast and sets of the sequence to tick and what it misses to make, and makes the shots with the ticked ones', function () {
        PlanDirector::fake([directorReply(['reply' => 'I lay out the shots for it.', 'direct' => 'A visitor reports a cracked badge at the counter.'])]);
        $visitor = Element::factory()->for($this->project)->create(['type' => ElementType::PERSON, 'name' => 'Visitor']);
        $desk = Element::factory()->for($this->project)->create(['type' => ElementType::PLACE, 'name' => 'Reception desk']);
        $plant = Element::factory()->for($this->project)->create(['type' => ElementType::OBJECT, 'name' => 'Plant']);
        $frame = fn(string $holds) => [...directedFrame('Reception desk, a plant on the counter.', $holds), 'elements' => ['Visitor', 'Plant']];
        SceneDirector::fake([directedSequence([
            'new_elements' => [['name' => 'Cracked badge', 'type' => 'object', 'description' => 'A white badge with a crack across it.'], ['name' => 'Plant', 'type' => 'object', 'description' => 'A plant.']],
            'shots' => [
                [...directedShot('Report it', 'He holds out the cracked badge.', shows: [1, 2, 3]), 'start' => $frame(''), 'end' => $frame('the Cracked badge')],
            ],
        ])]);
        $shot = planShot($this->project);

        $offered = talkTo($this, $shot, 'he reports a cracked badge')->assertJsonPath('reload', true);

        expect($offered->json('messages.1.cast'))->toEqualCanonicalizing([$visitor->sqid, $plant->sqid, $desk->sqid])
            ->and($shot->fresh()->storyline['new_elements'])->toBe([['name' => 'Cracked badge', 'type' => 'object', 'description' => 'A white badge with a crack across it.']]);

        $badge = Element::factory()->for($this->project)->create(['type' => ElementType::OBJECT, 'name' => 'Cracked badge']);

        actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => 1, 'accept' => true, 'cast' => [$visitor->sqid, $desk->sqid, $plant->sqid, $badge->sqid]])
            ->assertOk()
            ->assertJsonPath('messages.1.sequence.state', 'accepted');

        expect($shot->fresh()->plan_chat[1]['cast'])->toEqualCanonicalizing([$visitor->sqid, $desk->sqid, $plant->sqid, $badge->sqid]);

        $keyframes = $shot->fresh()->storylineKeyframes();

        expect($keyframes[0]['elements'])->toEqualCanonicalizing(['Visitor', 'Reception desk', 'Plant'])
            ->and($keyframes[1]['elements'])->toEqualCanonicalizing(['Visitor', 'Reception desk', 'Plant', 'Cracked badge']);
    });

    it('directs the sequence again without what the director unticked, and makes the shots once it is accepted as it is', function () {
        PlanDirector::fake([directorReply(['reply' => 'I lay out the shots for it.', 'direct' => 'A visitor reports a cracked badge at the counter.'])]);
        $visitor = Element::factory()->for($this->project)->create(['type' => ElementType::PERSON, 'name' => 'Visitor']);
        $desk = Element::factory()->for($this->project)->create(['type' => ElementType::PLACE, 'name' => 'Reception desk']);
        $plant = Element::factory()->for($this->project)->create(['type' => ElementType::OBJECT, 'name' => 'Plant']);
        $sequence = fn(string $place, array $elements) => directedSequence(['shots' => [
            [...directedShot('Report it', 'He reports the badge.', shows: [1, 2, 3]), 'start' => [...directedFrame($place, ''), 'elements' => $elements], 'end' => [...directedFrame($place, ''), 'elements' => $elements]],
        ]]);
        SceneDirector::fake([
            $sequence('Reception desk, a plant on the counter.', ['Visitor', 'Plant']),
            $sequence('Reception desk, a bare counter.', ['Visitor', 'Plant']),
        ]);
        $shot = planShot($this->project);
        $accept = fn(array $cast) => actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => 1, 'accept' => true, 'cast' => $cast]);

        talkTo($this, $shot, 'he reports a cracked badge');

        $accept([$visitor->sqid, $desk->sqid])
            ->assertOk()
            ->assertJsonPath('reload', false)
            ->assertJsonMissingPath('messages.1.sequence.state')
            ->assertJsonPath('messages.1.sequence.shots.0.start.staging.place', 'Reception desk, a bare counter.')
            ->assertJsonPath('messages.1.sequence.shots.0.start.elements', ['Visitor', 'Reception desk'])
            ->assertJsonPath('messages.1.left_out', ['Plant']);

        expect($shot->fresh()->plan_chat[1]['cast'])->toEqualCanonicalizing([$visitor->sqid, $desk->sqid])
            ->and($shot->fresh()->idea)->not->toBe('He reports the badge.');
        SceneDirector::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'never use them, and choose another of the cast and sets where the story needs one: Plant')
            && str_contains($prompt->prompt, "The story to direct, in the director's words: A visitor reports a cracked badge at the counter."));

        $accept([$visitor->sqid, $desk->sqid])->assertOk()->assertJsonPath('messages.1.sequence.state', 'accepted');

        expect($shot->fresh()->storylineKeyframes()[0]['elements'])->toEqualCanonicalizing(['Visitor', 'Reception desk']);
    });

    it('updates the sequence when it is accepted again, instead of adding it twice', function () {
        PlanDirector::fake([
            directorReply(['reply' => 'Laid out.', 'direct' => 'The host stops the visitor at the dock and points to a safe spot.']),
            directorReply(['reply' => 'Laid out again.', 'direct' => 'The host stops the visitor at the dock and points away from it.']),
        ]);
        $sequence = fn(string $second) => directedSequence(['facts' => ['The host stops him.', 'The host points.'], 'shots' => [
            directedShot('Ask first', 'The host stops the visitor at the dock.', shows: [1]),
            directedShot('Allowed spot', $second, shows: [2]),
        ]]);
        SceneDirector::fake([$sequence('The host points to a safe spot.'), $sequence('The host points away from the dock.')]);
        $shot = planShot($this->project, ['position' => 1]);
        $accept = fn(int $turn) => actingAs($this->director, 'director')
            ->postJson(route('public.shots.plan.sequence', [$this->project, $shot]), ['turn' => $turn, 'accept' => true])
            ->assertOk();

        talkTo($this, $shot, 'ok');
        $accept(1);
        talkTo($this, $shot->fresh(), 'perfect');
        $accept(3);

        $shots = $this->project->shots()->get();

        expect($shots)->toHaveCount(2)
            ->and($shots[1]->idea)->toBe('The host points away from the dock.')
            ->and($shots[1]->plan_chat)->toHaveCount(1)
            ->and($shots[1]->plan_chat[0]['text'])->toContain('The host points away from the dock.');
    });

    it('does not offer a sequence that leaves a fact unseen or shows what must not happen', function () {
        PlanDirector::fake([directorReply(['reply' => 'I lay out the shots.', 'direct' => 'He reaches for the bag but does not touch it.'])]);
        SceneDirector::fake([directedSequence([
            'facts' => ['He reaches for the bag.', 'He stops before he touches it.', 'The owner comes back.'],
            'must_not' => ['the hand touches the bag'],
            'shots' => [
                directedShot('Reach', 'He reaches for the bag.', shows: [1]),
                directedShot('Stop', 'He stops.', shows: [2], end: directedFrame('Hall. The hand touches the bag.')),
            ],
        ])]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'he reaches for the bag')
            ->assertJsonPath('messages.1.text', 'I lay out the shots. (The sequence does not hold up yet: No shot shows: The owner comes back. Shot 2 has in its end frame what must not happen: the hand touches the bag Tell me the story again, or what to change.)')
            ->assertJsonMissingPath('messages.1.sequence');

        expect($this->project->shots()->count())->toBe(1);
    });

    it('removes only untouched shots of the sequence the director agreed to drop', function () {
        $shot = planShot($this->project, ['position' => 1, 'group_key' => 'g1']);
        $talked = planShot($this->project, ['position' => 2, 'group_key' => 'g1', 'plan_chat' => [['role' => 'assistant', 'text' => 'Idea'], ['role' => 'director', 'text' => 'yes']]]);
        $duplicate = planShot($this->project, ['position' => 3, 'group_key' => 'g1', 'plan_chat' => [['role' => 'assistant', 'text' => 'Idea']]]);
        $elsewhere = planShot($this->project, ['position' => 4]);
        PlanDirector::fake([directorReply(['reply' => 'Removed.', 'remove_shots' => ['SH010', 'SH020', 'SH030', 'SH040']])]);

        talkTo($this, $shot, 'yes, remove them')->assertJsonPath('reload', true);

        expect(Shot::query()->find($duplicate->id))->toBeNull()
            ->and($shot->fresh())->not->toBeNull()
            ->and($talked->fresh())->not->toBeNull()
            ->and($elsewhere->fresh()->position)->toBe(3)
            ->and($this->project->shots()->pluck('position')->all())->toBe([1, 2, 3]);
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
        Illuminate\Support\Facades\Config::set('pipeline.voice_over.automatic', true);
        Queue::fake();
        PlanDirector::fake([directorReply(['proposal' => agreedPlan(['seconds' => 5])])]);
        $shot = planShot($this->project, [
            'duration' => 8,
            'voice_over' => 'An old text for nine seconds.',
            'chosen_storyline' => ['storyline' => 'A long plan.'],
            'storyline' => ['framing' => ['spot' => 'The counter.', 'seconds' => 9], 'keyframes' => [['title' => 'Old', 'description' => 'A long plan.']]],
        ]);

        talkTo($this, $shot, 'yes');

        expect($shot->fresh())
            ->durationInSeconds()->toBe(5)
            ->voice_over->toBeNull()
            ->and($shot->fresh()->storylineFraming())->toBe(['spot' => 'The counter.', 'seconds' => 5]);
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
        PlanDirector::fake([directorReply(['proposal' => agreedPlan(['keyframes' => [['title' => 'Clip', 'description' => 'The Badge clip on the vest.', 'spatial' => '', 'elements' => ['Badge clip']]]])])]);
        $shot = planShot($this->project);

        talkTo($this, $shot, 'yes');

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
        Shot::factory()->for($this->project)->create(['position' => 2, 'status' => ShotStatus::VIDEO_PENDING]);
        $drawing = Shot::factory()->for($this->project)->create(['position' => 3, 'status' => ShotStatus::KEYFRAMES_READY]);
        Keyframe::factory()->for($drawing)->create(['position' => 1, 'status' => KeyframeStatus::DRAWING]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $idle]))
            ->assertInertia(fn($page) => $page
                ->where('siblings.0.busy', false)
                ->where('siblings.1.busy', true)
                ->where('siblings.2.busy', true));
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
            ->from(route('public.shots.view', [$this->project, $first]))
            ->post(route('public.shots.reorder', $this->project), ['groups' => [[$third->sqid], [$first->sqid], [$second->sqid]]])
            ->assertRedirect(route('public.shots.view', [$this->project, $first]));

        expect($third->fresh()->position)->toBe(1)
            ->and($first->fresh()->position)->toBe(2)
            ->and($second->fresh()->position)->toBe(3);
    });

    it('groups the shots dropped on each other, keeps a group\'s key and takes a shot dragged out of its group out of it', function () {
        [$first, $second, $third, $fourth] = Shot::factory()->for($this->project)->count(4)->sequence(
            ['position' => 1, 'group_key' => 'sequence-a'],
            ['position' => 2, 'group_key' => 'sequence-a'],
            ['position' => 3, 'group_key' => 'sequence-a'],
            ['position' => 4],
        )->create();

        // The third leaves its group and forms a new one with the fourth; the first stays on its own.
        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['groups' => [[$second->sqid, $first->sqid], [$fourth->sqid, $third->sqid]]])
            ->assertSessionHasNoErrors();

        $key = $fourth->fresh()->group_key;

        expect([$second->fresh()->position, $first->fresh()->position, $fourth->fresh()->position, $third->fresh()->position])->toBe([1, 2, 3, 4])
            ->and($first->fresh()->group_key)->toBe('sequence-a')
            ->and($second->fresh()->group_key)->toBe('sequence-a')
            ->and($key)->not->toBeNull()->not->toBe('sequence-a')
            ->and($third->fresh()->group_key)->toBe($key);

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['groups' => [[$second->sqid], [$first->sqid], [$fourth->sqid, $third->sqid]]])
            ->assertSessionHasNoErrors();

        expect($first->fresh()->group_key)->toBeNull()
            ->and($second->fresh()->group_key)->toBeNull();
    });

    it('rejects an order that does not cover every shot, or lists one twice', function () {
        [$first] = Shot::factory()->for($this->project)->count(2)->sequence(['position' => 1], ['position' => 2])->create();

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['groups' => [[$first->sqid]]])
            ->assertSessionHasErrors('groups');

        actingAs($this->director, 'director')
            ->post(route('public.shots.reorder', $this->project), ['groups' => [[$first->sqid], [$first->sqid]]])
            ->assertSessionHasErrors('groups');
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

        $this->assertModelMissing($second);
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

    it('destroys the ticked shots, closes the gaps and stays on the open shot', function () {
        [$first, $second, $third, $fourth] = Shot::factory()->for($this->project)->count(4)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
            ['position' => 4],
        )->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy.many', $this->project), ['shots' => [$second->sqid, $fourth->sqid], 'current' => $third->sqid])
            ->assertRedirect(route('public.shots.view', [$this->project, $third]));

        $this->assertModelMissing($second);
        $this->assertModelMissing($fourth);
        expect($first->fresh()->position)->toBe(1)
            ->and($third->fresh()->position)->toBe(2);
    });

    it('opens the shot that took the place of the first deleted one when the open shot is deleted too', function () {
        [$first, $second, $third] = Shot::factory()->for($this->project)->count(3)->sequence(
            ['position' => 1],
            ['position' => 2],
            ['position' => 3],
        )->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy.many', $this->project), ['shots' => [$first->sqid, $second->sqid], 'current' => $second->sqid])
            ->assertRedirect(route('public.shots.view', [$this->project, $third]));

        expect($third->fresh()->position)->toBe(1);
    });

    it('deletes no shots when one of them is not in the project', function () {
        $shot = Shot::factory()->for($this->project)->create(['position' => 1]);
        $other = Shot::factory()->create();

        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy.many', $this->project), ['shots' => [$shot->sqid, $other->sqid]])
            ->assertSessionHasErrors('shots');

        $this->assertModelExists($shot);
    });
});
