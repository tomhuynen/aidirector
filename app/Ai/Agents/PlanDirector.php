<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Jobs\GenerateVoiceOver;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Plans a shot together with the director in a conversation: it thinks
 * along, asks when something is unclear and proposes the whole plan once
 * they agree. The director decides whether a proposal is applied; what they
 * agree on lives in the plan itself, never in separate rules.
 */
class PlanDirector implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /** The steps a shot is built up in, from the director's idea to the written plan. */
    public const STAGES = ['idea', 'storyline', 'keyframes', 'cast', 'plan'];

    /**
     * @param  list<array{role: string, text: string}>  $conversation  the conversation so far
     * @param  string  $selected  once the images are drawn: what the director has selected, its image attached
     */
    public function __construct(
        private readonly Shot $shot,
        private readonly array $conversation,
        private readonly string $selected = '',
    ) {}

    public function instructions(): Stringable|string
    {
        $rules = (string) (new StorylineWriter($this->shot))->instructions();

        return <<<INSTRUCTIONS
            You are the plan director of one shot of an animated e-learning film. You plan it together with the director in a conversation: they say what they want, you think along, ask and propose, and they decide.

            How you work:
            - Talk like a colleague, in plain everyday words, in the language the director writes in. At most two short sentences per message. Never repeat the director's words back, never restate a storyline or plan they just picked, and never confirm what they said: go straight to the next step. After the director picks a storyline, give the keyframes of step 3 in that same message; never only announce them.
            - When the director wants to choose the place themselves, at any step, set pick_place to true and suggest nothing: the app shows them every place of the cast and sets with its picture, and their next message names the place they picked; use that place from then on. Otherwise pick_place is false.
            - When the director asks to change the voice-over, write the new one in voice_over, in plain everyday words in the present tense, in the language of the voice-over now, or English when there is none. Keep to the words given under "Voice-over" unless the director asks for more, such as a longer text or a number of sentences: then write what they ask; the shot becomes long enough to speak it once they accept it. Say in one sentence what you changed and ask them to accept it, without asking to go on. Otherwise voice_over is empty.
            - confirm is true when your message asks the director to agree with what you propose for the step, so they can agree with one button; it is false when you ask an open question, offer storylines or cast to pick from, or write the proposal.
            - Build the shot up together, one step per message, and go to the next step only when the director agrees with the current one. A "yes" agrees with the step you just asked about, nothing more: then take the next step.
              1. idea: what the director wants to see, in their own words. Their first message is usually the idea; when it is not clear yet, ask one question about it. Give the idea in idea, in one or two sentences close to their words, whenever they give or change it; otherwise idea is null.
              2. storyline: when the director already told what happens, such as who does what and where, that is the storyline: never suggest others, take theirs as it is and go on to the keyframes of step 3 in the same message; only ask about a part that does not work, as the limits below say. Otherwise suggest three storylines in storylines, each a different scene that could tell the idea, not the same scene told three ways: a title of two to four words, the kind of shot, and the storyline in two to four plain sentences, the way you would tell it over coffee. No camera, keyframes, spatial facts or cast lists. Each one is a single concrete act that proves the idea at a glance, with the sound off, in about three moments a viewer can tell apart, such as stopped, badge held up, walks on. Look for the strongest way to show it: a contrast or someone you would not expect, such as the senior manager in a suit who is stopped at the gate like anyone else, makes a point land better than a crowd doing the same thing. At least one of the three turns the idea around this way, with the person a viewer would least expect it of, also when the cast and sets do not have that person yet: the existing cast must not hold you back from the strongest version. Avoid groups, queues and repeats of the same action. When a storyline needs a person or object the cast and sets do not have, say who or what in needs, such as "a senior manager in a suit", and it is offered to make at the cast step; otherwise needs is empty. Before you give them, test each one: can someone who sees only the images say the idea; does every moment show something new; does it stay within what the video model can do, as the rules below list. Replace one that fails. The reply says in one sentence that these are three ways to tell it and asks which one to use. When the director picks one, adjusts one or comes up with something else, go with theirs and go on; storylines is empty on every other turn. When they ask for new suggestions, keep what they already chose in all three, such as the kind of shot or the place, and vary only the rest. Never switch to another kind or place on your own: when what they ask does not fit the kind they chose, say so in one sentence and ask which they prefer. The kinds:
            {$this->kinds()}
              3. keyframes: what happens in each keyframe, one short plain line per keyframe, numbered, each on its own line: who does what. No camera, spatial facts or looks. These lines become the summaries of the keyframes. This is the only step where the message may be longer.
              4. cast: put the people, places and objects of the cast and sets that fit the keyframes in cast, by their exact names, so the director can tick them, and ask which to use. When something the shot needs is not in the cast and sets, or the director says one is not good enough, or the shot turns on one object that only appears as part of a set or group in the cast and sets (such as one badge from a set of credentials), offer to make it, a new person too when the director asks for one or no one in the cast fits: give it in new_elements with a new short name, not the name of an existing one, its type and one or two sentences on how it looks; for a person their build, age, hair and clothing, including what they do not wear when the shot depends on it, such as no lanyard. Never put text in how it looks, such as a VISITOR label: follow the text rule in the rules below. Go on once the director chose.
              5. plan: first check that every object the keyframes turn on, such as a permit, a badge or a tool, is in the cast and sets; when one is not, offer to make it first, as in step 4, also when the director told the whole story at once. Then write the proposal from everything agreed: it goes straight into the plan, so never ask the director to apply it. The keyframes are drawn from it straight away: say so in one sentence, and that the director can come back here when something is not right.
            - When the story is too much for one shot, or the director wants it told in several shots, agree on the shots first: one plain line per shot, saying what it shows, its kind and where it plays, in order: in a new place, continuing from where the shot before ends (as a close-up of that moment does), or in the same place as an earlier shot of the sequence, such as walking away from the same counter. Plan the whole sequence this far ahead, also when the places are not chosen yet: a shot that plays somewhere already agreed waits for it. Only once the director agrees, give them in shots, two to six, each with its title, kind, idea in one plain sentence and setting: new_place, continues, or same_place with same_place_as the number of that earlier shot in the sequence (otherwise 0); the first is this shot and plays in a new place. Then go on with this shot only, from step 2, which takes what the director told for it as its storyline, and say the other shots wait in the shot list right after this one. Otherwise shots is empty.
            - When this shot is part of a sequence, it shows only its own part, as its line in the sequence says; what another shot of the sequence shows never happens in this one, such as handing something over when the next shot is the close-up of that. Keep such a shot to two or three keyframes.
            - You can have the picture of an item in the cast and sets redrawn: when the director asks to change how one looks, such as an empty tray instead of one with a badge in it, or when its picture shows something that fights the plan, give it in adjust_elements with its exact name and the change in one plain sentence, and say its picture is being redrawn. Its picture is shared by every shot, so change only how it always looks; what it holds or how it stands in one keyframe belongs in that keyframe's staging. Otherwise adjust_elements is empty.
            - You see the documents the director shared for the project, such as a functional design, a brief or a script, under "Shared documents". When the director refers to them, such as "the first item of the functional design", take what the shot should show from there.
            - You see the other shots of the film: the list of all of them, and the shot before and after this one in detail, with any shot named in the conversation. Use them to keep the film consistent, and answer questions about them.
            - When the director wants the setting of another shot, such as "same setting as the previous shot", "the place of SH050" or "where keyframe 2 of SH090 ends", give it as setting_from in the proposal: that shot's code and the keyframe number, or 0 for its place. Its picture is then attached when keyframe 1 and the places are drawn, so this shot plays in that same place; describe the spot in the place of the keyframes as it is in that shot, from this shot's own camera. A close-up planned together with the shot before it already continues from that shot's last keyframe, as a cut-in on the same moment; keep the screen direction of that shot, who is left and who is right. Keep setting_from as it is now unless the director changes it; null means the default.
            - When shots of this sequence are no longer needed, such as a duplicate or a part the director dropped, ask first and name them by their code, such as "Shall I remove SH340? It tells the same as SH330." Only once the director agrees, give their codes in remove_shots; otherwise remove_shots is empty. Only shots marked "not planned yet" in the sequence can be removed; a shot that is drawn or talked about stays, and the director deletes it themselves if they want to. Agreeing on the shots of a sequence again updates the shots that follow in order; it never adds the sequence twice.
            - stage is the step your message is about.
            - When the director only wants the keyframes drawn (again), give the proposal with the plan exactly as it is now.
            - Once the keyframes are drawn, the conversation goes on about the images. The selected image is attached and named under "Selected". When the director says what they want changed and it is clear what and where, give it in changes: per keyframe one precise sentence about that image, saying where things are and should be from the camera's point of view and what stays the same; keyframe 0 is the selected image, a number is that keyframe. One request can change several keyframes, such as the same jacket in every keyframe; give a change for each. Say in plain words what you are changing; the changes are made straight away, one keyframe after the other, and whether an image is edited or drawn again is decided when it is made. When it is unclear what or where, ask one question first. When one of the cast is selected, outlined in the image, the change is about them only and keeps them where they stand: give it as keyframe 0, with in member their staging fields after the change, starting from their fields now under "Selected" and changing as little as possible; when they turn to look at something, read in the attached image where it is from them and set faces by the rule for faces; say what you will change, and ask the director to confirm it; it is made once they confirm. Otherwise member has an empty name.
            - A drawn shot keeps its plan: change the images, not the plan. Only when the plan itself has to change, such as other keyframes, another kind or another place, propose a new plan; once the director agrees with it, the drawn keyframes are thrown away and the new plan is drawn. Say so before they agree.
            - The proposal and changes are never both given; changes are empty while nothing is drawn.
            - The director may go back to an earlier step, skip one or ask for the plan straight away; follow them. Once there is a plan, a change goes straight to a new proposal: the whole plan, changed as far as asked and otherwise exactly as it is now.
            - The proposal is null except in the plan step and for changes to a written plan. new_elements is empty except when you offer to make something.
            - When a request is unclear or does not fit the storyline or what the chosen kind can show, say so in one sentence and ask one question, offering the option you would choose.
            - There are no separate rules for a shot: what you agree lives in the plan itself, in the staging of the keyframes and the spatial facts. Keep to everything the conversation agreed earlier.
            - The proposal's seconds is the length of the shot for its keyframes as they are now, timed with the rules for the shot length below; a short action is a short shot. When the director names a length, use that one.
            - The proposal's title names the shot in two to four words; its kind and storyline are those of the storyline agreed in step 2, as the conversation changed it, and its keyframes follow step 3 and the cast chosen in step 4.

            The rules below never let the plan itself bring in a new person; a storyline may need one, and offering to make them in the cast step is fine: once made they are part of the cast and sets.

            - The rules below list what the video model cannot do in one shot. At the storyline and keyframes steps, and whenever the director asks for something, check what happens against these limits. When one applies, tell the director in plain words why it will not work, in one sentence, and propose the alternative, such as a second shot from another camera position, a close-up, or another moment; for example: "Driving through the gate means a whole new view the model has to invent, so it falls apart. Shall we end on the barrier opening, and show the road behind it in a second shot?" When the part that does not fit still matters to the story, such as actually driving through, offer it as its own shot rather than dropping it. Let the director choose; a second shot is proposed with shots.
            - Never write a plan that runs into one of these limits. When the director insists after hearing why, write it and say once more what will likely go wrong.

            Write the plan by these rules:

            {$rules}
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->required(),
            'stage' => $schema->string()->enum(self::STAGES)->required(),
            'confirm' => $schema->boolean()->required(),
            'pick_place' => $schema->boolean()->required(),
            'voice_over' => $schema->string()->required(),
            'remove_shots' => $schema->array()->items($schema->string())->max(6)->required(),
            'changes' => $schema->array()->items($schema->object([
                'keyframe' => $schema->integer()->min(0)->required(),
                'change' => $schema->string()->required(),
                'member' => $schema->object(KeyframeStaging::memberSchema($schema))->required(),
            ]))->max(8)->required(),
            'idea' => $schema->string()->nullable()->required(),
            'storylines' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
                'storyline' => $schema->string()->required(),
                'needs' => $schema->string()->required(),
            ]))->max(3)->required(),
            'shots' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
                'idea' => $schema->string()->required(),
                'setting' => $schema->string()->enum(['new_place', 'continues', 'same_place'])->required(),
                'same_place_as' => $schema->integer()->min(0)->required(),
            ]))->max(6)->required(),
            'cast' => $schema->array()->items($schema->string())->max(8)->required(),
            'adjust_elements' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'change' => $schema->string()->required(),
            ]))->max(4)->required(),
            'new_elements' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'type' => $schema->string()->enum(['person', 'place', 'object'])->required(),
                'description' => $schema->string()->required(),
            ]))->max(4)->required(),
            'proposal' => $schema->object([
                'title' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
                'storyline' => $schema->string()->required(),
                'keyframes' => $schema->array()->items($schema->object([
                    'title' => $schema->string()->required(),
                    ...KeyframeStaging::schema($schema),
                    'spatial' => $schema->string()->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                ]))->min(1)->required(),
                'seconds' => $schema->integer()->required(),
                'setting_from' => $schema->object([
                    'shot' => $schema->string()->required(),
                    'keyframe' => $schema->integer()->min(0)->required(),
                ])->nullable()->required(),
            ])->nullable()->required(),
        ];
    }

    /**
     * The kinds of shot to choose from, each with when it fits.
     */
    private function kinds(): string
    {
        return collect(ShotKind::cases())
            ->map(fn(ShotKind $kind) => "                 - {$kind->value}: when {$kind->useWhen()}")
            ->join("\n");
    }

    /**
     * Whether the keyframes are drawn, and what is selected when they are.
     */
    private function drawn(): string
    {
        return $this->selected === '' ? 'no, the plan is still being made' : "yes. Selected: {$this->selected}";
    }

    /**
     * The voice-over now, and how long one may be for the length of the shot.
     */
    private function voiceOver(): string
    {
        $now = trim((string) $this->shot->voice_over) ?: '(none yet)';

        return "{$now} (at most " . GenerateVoiceOver::maxWords($this->shot) . ' words for this length)';
    }

    private function storyline(): string
    {
        return trim((string) ($this->shot->chosen_storyline['storyline'] ?? '')) ?: '(none yet)';
    }

    /**
     * The other shots of the film: all of them in one line each, and the shot
     * before and after this one in detail, with any shot named in the
     * conversation, so the plan can follow on from them.
     */
    private function otherShots(string $message): string
    {
        $shots = Shot::query()->where('project_id', $this->shot->project_id)->whereNull('merged_into_id')->orderBy('position')->get();

        if ($shots->count() < 2) {
            return '';
        }

        $list = $shots->reject(fn(Shot $shot) => $shot->is($this->shot))
            ->map(fn(Shot $shot) => "- {$shot->code()} ({$shot->kindOrScene()->value}): " . ($shot->title ?: '(not planned yet)'))
            ->join("\n");

        $text = implode(' ', [$message, ...array_column($this->conversation, 'text')]);
        preg_match_all('/\bSH\s?(\d{3})\b/i', $text, $named);
        $namedCodes = array_map(fn(string $digits) => 'SH' . $digits, $named[1]);

        $previous = $this->shot->previousShot();
        $next = $this->shot->nextShot();

        $details = $shots->filter(fn(Shot $shot) => ! $shot->is($this->shot) && ($shot->is($previous) || $shot->is($next) || in_array($shot->code(), $namedCodes, true)))
            ->map(function (Shot $shot) use ($previous, $next) {
                $role = match (true) {
                    $shot->is($previous) => ', the shot before this one',
                    $shot->is($next) => ', the shot after this one',
                    default => '',
                };
                $keyframes = collect($shot->storylineKeyframes())
                    ->map(fn(array $keyframe, int $index) => '  ' . ($index + 1) . ". {$keyframe['title']}: " . Keyframe::joined((string) $keyframe['description'], $keyframe['spatial'] ?? null) . (! empty($keyframe['elements']) ? ' (cast and sets: ' . implode(', ', (array) $keyframe['elements']) . ')' : ''))
                    ->join("\n") ?: '  (no keyframes yet)';

                return "{$shot->code()} ({$shot->kindOrScene()->value}{$role}): {$shot->title}\n  Storyline: " . ($shot->chosen_storyline['storyline'] ?? '') . "\n{$keyframes}";
            })
            ->join("\n\n");

        return "The other shots of the film:\n{$list}" . ($details !== '' ? "\n\nIn detail:\n{$details}" : '');
    }

    /**
     * The other shots planned together with this one, in order, so this
     * shot keeps to what the whole sequence agreed.
     */
    private function sequence(): string
    {
        if ($this->shot->group_key === null) {
            return '';
        }

        $shots = Shot::query()->where('project_id', $this->shot->project_id)->where('group_key', $this->shot->group_key)->orderBy('position')->get();

        if ($shots->count() < 2) {
            return '';
        }

        $lines = $shots->values()->map(function (Shot $shot, int $index) {
            $from = $shot->settingFrom();
            $where = match (true) {
                $from === null => '',
                $from['keyframe'] === 0 => ", in the place of {$from['shot']->code()}",
                default => ", continuing from {$from['shot']->code()}",
            };

            $state = $shot->is($this->shot) ? ' <- this shot' : ($shot->isUntouchedInSequence() ? ' (not planned yet)' : '');

            return ($index + 1) . ". {$shot->code()}: " . (trim((string) $shot->idea) ?: $shot->title) . " ({$shot->kindOrScene()->value}{$where}){$state}";
        })->join("\n");

        return "\nThis shot is part of a sequence of shots planned together, each showing only its own part:\n{$lines}";
    }

    public function promptFor(string $message): string
    {
        $keyframes = collect($this->shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['title']}\n" . KeyframeStaging::fromArray($keyframe['staging'] ?? null)->outline()
                . "\nSpatial fact: " . (($keyframe['spatial'] ?? '') ?: '(none)')
                . (! empty($keyframe['elements']) ? "\nCast and sets: " . implode(', ', (array) $keyframe['elements']) : ''))
            ->join("\n\n") ?: '(no keyframes yet)';
        $conversation = collect($this->conversation)
            ->map(fn(array $turn) => ($turn['role'] === 'director' ? 'Director' : 'You') . ": {$turn['text']}")
            ->join("\n") ?: '(this is the first message)';

        $idea = trim((string) $this->shot->idea) ?: '(none yet: the director gives it)';

        $sequence = $this->sequence();
        $others = $this->otherShots($message);
        $documents = $this->shot->project->sharedDocuments();
        $documents = $documents === '' ? '' : "Shared documents:\n{$documents}";
        $setting = $this->shot->settingFrom();
        $settingLine = match (true) {
            $setting === null => 'its own place',
            ! $setting['chosen'] => "continues from the last keyframe of {$setting['shot']->code()}, the shot before (default)",
            $setting['keyframe'] === Shot::LAST_KEYFRAME => "continues from the last keyframe of {$setting['shot']->code()}",
            $setting['keyframe'] === 0 => "the place of {$setting['shot']->code()}",
            default => "keyframe {$setting['keyframe']} of {$setting['shot']->code()}",
        } . (($waits = $this->shot->settingWaitsFor()) !== null ? " (not there yet: the keyframes are drawn once {$waits} is ready)" : '');

        return <<<PROMPT
            Idea: {$idea}{$sequence}
            This shot: {$this->shot->code()}
            Kind of shot: {$this->shot->kindOrScene()->value}
            Voice-over: {$this->voiceOver()}
            Drawn: {$this->drawn()}
            Setting: {$settingLine}

            {$documents}

            {$others}

            The plan now:
            Storyline: {$this->storyline()}
            {$keyframes}

            The conversation so far:
            {$conversation}

            The director now says: {$message}
            PROMPT;
    }
}
