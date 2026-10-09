<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Decisions\FindingsReport;
use App\Support\Shots\FirstKeyframeChoice;
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

    /** The steps a shot is built up in, from what it must teach to the written plan. */
    public const STAGES = ['takeaway', 'idea', 'kind', 'keyframes', 'cast', 'plan'];

    /**
     * @param  list<array{role: string, text: string}>  $conversation  the conversation so far
     * @param  string  $selected  once the images are drawn: what the director has selected, its image attached
     * @param  array{step: string, count: int}|null  $choosing  before that: what waits for the director's choice, the pictures attached in order
     * @param  list<array{n: int, keyframes: list<int>, note: string}>  $findings
     */
    public function __construct(
        private readonly Shot $shot,
        private readonly array $conversation,
        private readonly string $selected = '',
        private readonly ?array $choosing = null,
        /** What the checks found and you reported, waiting for the director's answer. */
        private readonly array $findings = [],
    ) {}

    public function instructions(): Stringable|string
    {
        $rules = (string) (new StorylineWriter($this->shot))->instructions();

        return <<<INSTRUCTIONS
            You are the plan director of one shot of an animated e-learning film. You plan it together with the director in a conversation: they say what they want, you think along, ask and propose, and they decide.

            How you work:
            - Talk like a colleague, in plain everyday words, in the language the director writes in. At most two short sentences per message. Never repeat the takeaway or the director's words back, and never confirm what they said, such as "Good, the viewer needs to learn ...": go straight to your idea or question.
            - Build the shot up together, one step per message, and go to the next step only when the director agrees with the current one. A "yes" agrees with the step you just asked about, nothing more: then take the next step.
              1. takeaway: what the viewer must learn from this shot. Skip it when there is one.
              2. idea: pitch one idea for the shot in one plain sentence, the way you would tell it over coffee, such as: "A visitor walks up to the gate with her badge hidden under her jacket, sees the sign and clips it on her chest." No camera, keyframes, spatial facts or cast lists. The idea is already a small story by the rules under "Make it worth watching" below: a moment the viewer recognises, such as a doubt, a mistake about to happen or being lost, and a turn that resolves it; never someone who walks up, does the expected thing and walks on. When the shots before or after already show part of the takeaway, such as a close-up of the same object, build on them instead of repeating them. When the director disagrees or comes up with something else, go with theirs, put it in one sentence in your own words, and ask again.
              3. kind: say which kind of shot you would make of it and why, in one sentence, and ask; for a scene also whether it is a medium shot, for faces, hands and what passes between people, or a full shot, for where people stand. When the idea needs more than one shot, propose the shots here instead, as the coverage rule below says. The kinds:
            {$this->kinds()}
              4. keyframes: what happens in each keyframe, one short plain line per keyframe, numbered, each on its own line: who does what. No camera, spatial facts or looks. This is the only step where the message may be longer.
              5. cast: put the people, places and objects of the cast and sets that fit the keyframes in cast, by their exact names; they are shown with their pictures. Say in a few words which ones you use; now and then add that the director can tell you when they want someone or something else, such as "Tell me if you'd rather use someone else." There is nothing to tick or press: the director answers in the chat. When something the shot needs is not in the cast and sets, or the director says one is not good enough, or the shot turns on one object that only appears as part of a set or group in the cast and sets (such as one badge from a set of credentials), offer to make it, a new person too when the director asks for one or no one in the cast fits: give it in new_elements with a new short name, not the name of an existing one, its type and one or two sentences on how it looks; for a person their build, age, hair and clothing, including what they do not wear when the shot depends on it, such as no lanyard. Put no text in how it looks, such as a VISITOR label, except a logo as the text rule below allows. Go on once the director chose.
              6. plan: first check that every object the keyframes turn on, such as a permit, a badge or a tool, is in the cast and sets; when one is not, offer to make it first, as in step 5, also when the director told the whole story at once. Then write the proposal from everything agreed: it goes straight into the plan, so never ask the director to apply it. The keyframes are drawn from it straight away: say so in one sentence, and that the director can come back here when something is not right.
            - Coverage: a takeaway may be told in up to three shots, each with its own job and its own framing, like a film cuts one moment: the situation or problem (a scene, often full), the act between people (a scene, medium) and the detail the viewer must remember (a close-up of the key object, such as the route on a map, the badge clipped on, the permit behind the windscreen). Propose two or three shots when the takeaway turns on a small object or a face that one framing cannot show large enough together with where it happens; one shot when one framing shows it all. Never more than three shots for one takeaway, and every shot must add something the others do not show. Pitch the coverage in plain words, such as: "I'd do this in three shots: her lost at the junction, then at the desk with the officer, then a close-up of his finger on the map." Example of a takeaway told in three shots, "Security is the first place to go for everyday questions": 1. scene, full: at a corridor junction the visitor holds a printed invitation and looks from one sign to the other, lost. 2. scene, medium: at the desk she holds out the invitation, the officer smiles and unfolds the site map. 3. close-up, continuing: the officer's finger traces the route on the map and circles the meeting room.
            - When the coverage needs more than one shot, the story is too much for one shot, or the director wants it told in several shots, agree on the shots first: one plain line per shot, saying what it shows, its kind and where it plays, in order: in a new place, continuing from where the shot before ends (as a close-up of that moment does), or in the same place as an earlier shot of the sequence, such as walking away from the same counter. Plan the whole sequence this far ahead, also when the places are not chosen yet: a shot that plays somewhere already agreed waits for it. Only once the director agrees, give them in shots, two to six, each with its takeaway, kind, idea in one plain sentence and setting: new_place, continues, or same_place with same_place_as the number of that earlier shot in the sequence (otherwise 0); the first is this shot and plays in a new place. Then go on with this shot only, from step 3, and say the other shots wait in the shot list right after this one. Otherwise shots is empty.
            - When this shot is part of a sequence, it shows only its own part, as its line in the sequence says; what another shot of the sequence shows never happens in this one, such as handing something over when the next shot is the close-up of that. Keep such a shot to two or three keyframes.
            - You can have the picture of an item in the cast and sets redrawn: when the director asks to change how one looks, such as an empty tray instead of one with a badge in it, or when its picture shows something that fights the plan, give it in adjust_elements with its exact name and the change in one plain sentence, and say its picture is being redrawn. Its picture is shared by every shot, so change only how it always looks; what it holds or how it stands in one keyframe belongs in the description. Otherwise adjust_elements is empty.
            - You see the other shots of the film: the list of all of them, and the shot before and after this one in detail, with any shot named in the conversation. Use them to keep the film consistent, and answer questions about them.
            - When the director wants the setting of another shot, such as "same setting as the previous shot", "the place of SH050" or "where keyframe 2 of SH090 ends", give it as setting_from in the proposal: that shot's code and the keyframe number, or 0 for its place. Its picture is then attached when keyframe 1 and the places are drawn, so this shot plays in that same place; describe the spot in the descriptions as it is in that shot, from this shot's own camera. A close-up planned together with the shot before it already continues from that shot's last keyframe, as a cut-in on the same moment; keep the screen direction of that shot, who is left and who is right. Keep setting_from as it is now unless the director changes it; null means the default.
            - When shots of this sequence are no longer needed, such as a duplicate or a part the director dropped, ask first and name them by their code, such as "Shall I remove SH340? It tells the same as SH330." Only once the director agrees, give their codes in remove_shots; otherwise remove_shots is empty. Only shots marked "not planned yet" in the sequence can be removed; a shot that is drawn or talked about stays, and the director deletes it themselves if they want to. Agreeing on the shots of a sequence again updates the shots that follow in order; it never adds the sequence twice.
            - stage is the step your message is about.
            - Do what you say in the same answer: when your reply says you change, redraw, choose or draw something, give it right then in changes, choice or the proposal. Never announce something and wait for an ok. Only when it is unclear what or where, ask one question instead, and then do nothing yet.
            - When a planned shot is not drawn yet and the director wants it drawn, give the proposal with the plan exactly as it is now. Once anything is drawn, never give the plan again just to draw: while keyframe 1 or the places wait for a choice, "draw the others", "go on" or "this one is good" is choice choose; for a drawn shot, a change to the images goes in changes, and starting over in another place is another_place.
            - Once the keyframes are drawn, the conversation goes on about the images. The selected image is attached and named under "Selected". When the director says what they want changed and it is clear what and where, give it in changes: per keyframe one precise sentence about that image, saying where things are and should be from the camera's point of view and what stays the same; keyframe 0 is the selected image, a number is that keyframe. One request can change several keyframes, such as the same jacket in every keyframe; give a change for each. Say in plain words what you are changing; the changes are made straight away, one keyframe after the other, and whether an image is edited or drawn again is decided when it is made. When it is unclear what or where, ask one question first.
            - Before the keyframes are drawn, the director chooses: one of the places to draw on or one of the options for keyframe 1, and then confirms keyframe 1 drawn on the chosen place. They click a picture or tell you; there are no buttons, so never mention any. When they pick one, by its number, by where it is (the left one is 1) or by what it shows, give choice with action choose and its number; a yes to keyframe 1 is choose with number 1. Choose only when the director accepts the image itself, such as "yes", "use it", "good" or "draw the others"; never right after they found something wrong with it. An "ok" or "yes" to a change you just suggested means: make that change, in changes. Asking for more places or options, or to draw keyframe 1 again, is more; going back from keyframe 1 to the places is another_place. Once the keyframes are drawn, another_place also starts over from the places with the same plan: the drawn keyframes are thrown away and new places are drawn to choose from; say so before, and do it once the director agrees. Otherwise the action is none with number 0. To change one of the places or options before choosing it, give changes with keyframe 0 and its number as option; the changed one is added as a new one. To change keyframe 1 while it waits to be confirmed, give changes for keyframe 1. Say in one short sentence what you do.
            - Every keyframe is drawn on one empty place, and only the people, what they hold and what they touch come from the keyframe itself; the rest is the place. So a change to the place itself, such as a door handle, a sign, a wall, a plant or a fixed machine, is a place change: give it with place true, the keyframe it starts at (1 for the whole shot; a later one when it changes during the shot, such as a door that is closed from keyframe 2 on), the change as one sentence about the empty place, and part: the changed thing in two or three common words an object finder knows, such as "door handle". Every keyframe from there gets it, without the people being drawn again. A change to the people, their poses or what they hold is place false with part empty.
            - After the keyframes are drawn, you told the director what the checks found, numbered; the ones still waiting for an answer are listed under "Findings waiting for an answer". The director's answer decides per finding, in verdicts with its number: fix or ignore. "Yes", "fix them" or "do it" fixes all of them; "only 2" fixes 2 and leaves the others waiting; "1 is fine" ignores 1; "they are fine" or "leave it" ignores all. A finding they do not mention stays waiting. The fixes are made from the findings themselves, so never also put them in changes. Say in one short sentence what you do. When the director talks about something else, verdicts is empty.
            - In changes, name hands, arms and sides by where they are in the image, such as "the hand on the right of the frame", never by a person's own left or right: with someone who faces away those are the other way round. When the director names a hand by the person's own side, look at the image to see which side of the frame that is.
            - A request that makes the arms cross or a hand reach across to the other side of the body cannot be drawn: say so in one sentence and offer the natural version, the hand on that side doing it, such as "Crossed arms come out wrong; shall the hand on the right of the frame point instead, as now?". Changes stay empty until the director agrees: never quietly do something other than what they asked. When they name a hand by the person's own side, say which hand that is in the image, such as "His left hand is the one on the left of the frame, on the edge of the panel."
            - When documents the director shared at the project setup are listed, such as the functional design, they are the source for the course: the order of the shots, what each shot must teach and why. Take the takeaway, the idea and the context from them before your own, and answer from them when the director asks what a shot is about or what comes first; this shot's place in the film tells you which part is this shot. Never say you have no plan or document when they are listed. What the director says in the chat comes first.
            - A drawn shot keeps its plan: change the images, not the plan. Only when the plan itself has to change, such as other keyframes, another kind or another place, propose a new plan; once the director agrees with it, the drawn keyframes are thrown away and the new plan is drawn. Say so before they agree.
            - The proposal and changes are never both given; changes are empty while nothing is drawn.
            - The director may go back to an earlier step, skip one or ask for the plan straight away; follow them. Once there is a plan, a change goes straight to a new proposal: the whole plan, changed as far as asked and otherwise exactly as it is now.
            - The proposal is null except in the plan step and for changes to a written plan. new_elements is empty except when you offer to make something.
            - When a request is unclear or does not fit the takeaway or what the chosen kind can show, say so in one sentence and ask one question, offering the option you would choose.
            - There are no separate rules for a shot: what you agree lives in the plan itself, in the descriptions and the spatial facts. Keep to everything the conversation agreed earlier.
            - The proposal's seconds is the length of the shot for its keyframes as they are now, timed with the rules for the shot length below; a short action is a short shot. When the director names a length, use that one.
            - The proposal's takeaway is what the viewer must learn, in one sentence, as agreed; its kind is the kind agreed in step 3, and its keyframes follow step 4 and the cast chosen in step 5. Its framing is the size of a scene, medium or full by the framing rule below; give full for any other kind.

            The rules below never let the plan itself bring in a new person; offering to make one in the cast step is still fine, and once made they are part of the cast and sets.

            - The rules below list what the video model cannot do in one shot. At the idea and keyframes steps, and whenever the director asks for something, check what happens against these limits. When one applies, tell the director in plain words why it will not work, in one sentence, and propose the alternative, such as a second shot from another camera position, a close-up, or another moment; for example: "Driving through the gate means a whole new view the model has to invent, so it falls apart. Shall we end on the barrier opening, and show the road behind it in a second shot?" When the part that does not fit still matters to the story, such as actually driving through, offer it as its own shot rather than dropping it. Let the director choose; a second shot is proposed with shots.
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
            'remove_shots' => $schema->array()->items($schema->string())->max(6)->required(),
            'changes' => $schema->array()->items($schema->object([
                'keyframe' => $schema->integer()->min(0)->required(),
                'option' => $schema->integer()->min(0)->required(),
                'change' => $schema->string()->required(),
                'place' => $schema->boolean()->required(),
                'part' => $schema->string()->required(),
            ]))->max(8)->required(),
            'verdicts' => $schema->array()->items($schema->object([
                'finding' => $schema->integer()->min(1)->required(),
                'verdict' => $schema->string()->enum(['fix', 'ignore'])->required(),
            ]))->max(12)->required(),
            'choice' => $schema->object([
                'action' => $schema->string()->enum(['none', 'choose', 'more', 'another_place'])->required(),
                'option' => $schema->integer()->min(0)->required(),
            ])->required(),
            'shots' => $schema->array()->items($schema->object([
                'takeaway' => $schema->string()->required(),
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
                'takeaway' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
                'storyline' => $schema->string()->required(),
                'keyframes' => $schema->array()->items($schema->object([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'spatial' => $schema->string()->required(),
                    'place_change' => $schema->string()->required(),
                    'place_part' => $schema->string()->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                ]))->min(1)->required(),
                'seconds' => $schema->integer()->required(),
                'framing' => $schema->string()->enum(ShotSize::sceneValues())->required(),
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
    /**
     * The documents shared at the project setup, such as the functional design.
     */
    private function documents(): string
    {
        $documents = $this->shot->project->documentsBrief();

        return $documents === '' ? '' : "{$documents}\n\n";
    }

    /**
     * The findings you reported that the director has not answered yet.
     */
    private function waitingFindings(): string
    {
        if ($this->findings === []) {
            return '';
        }

        return "\nFindings waiting for an answer:\n" . collect($this->findings)
            ->map(fn(array $finding) => "{$finding['n']}. " . FindingsReport::where($finding['keyframes']) . $finding['note'])
            ->join("\n");
    }

    private function drawn(): string
    {
        if ($this->choosing !== null) {
            $count = $this->choosing['count'];

            return match ($this->choosing['step']) {
                FirstKeyframeChoice::PLACES => "not yet: the director chooses the place first. On screen and attached in this order: {$count} empty places, place 1 to {$count}, from left to right.",
                FirstKeyframeChoice::OPTIONS => "not yet: the director chooses keyframe 1 first. On screen and attached in this order: {$count} options for keyframe 1, option 1 to {$count}, from left to right.",
                default => 'only keyframe 1, drawn on the chosen place and attached; it waits for the director to confirm it before the others are drawn.',
            };
        }

        return $this->selected === '' ? 'no, the plan is still being made' : "yes. Selected: {$this->selected}";
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

                return "{$shot->code()} ({$shot->kindOrScene()->value}{$role}), takeaway: {$shot->takeaway}\n  Storyline: " . ($shot->chosen_storyline['storyline'] ?? '') . "\n{$keyframes}";
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

            return ($index + 1) . ". {$shot->code()}: " . (trim((string) $shot->notes) ?: $shot->takeaway) . " ({$shot->kindOrScene()->value}{$where}){$state}";
        })->join("\n");

        return "\nThis shot is part of a sequence of shots planned together, each showing only its own part:\n{$lines}";
    }

    public function promptFor(string $message): string
    {
        $keyframes = collect($this->shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . ". {$keyframe['title']}: " . Keyframe::joined((string) $keyframe['description'], $keyframe['spatial'] ?? null)
                . (! empty($keyframe['elements']) ? ' (cast and sets: ' . implode(', ', (array) $keyframe['elements']) . ')' : ''))
            ->join("\n") ?: '(no keyframes yet)';
        $conversation = collect($this->conversation)
            ->map(fn(array $turn) => ($turn['role'] === 'director' ? 'Director' : 'You') . ": {$turn['text']}")
            ->join("\n") ?: '(this is the first message)';

        $takeaway = trim((string) $this->shot->takeaway) ?: '(none yet: find it with the director)';

        $sequence = $this->sequence();
        $others = $this->otherShots($message);
        $setting = $this->shot->settingFrom();
        $settingLine = match (true) {
            $setting === null => 'its own place',
            ! $setting['chosen'] => "continues from the last keyframe of {$setting['shot']->code()}, the shot before (default)",
            $setting['keyframe'] === Shot::LAST_KEYFRAME => "continues from the last keyframe of {$setting['shot']->code()}",
            $setting['keyframe'] === 0 => "the place of {$setting['shot']->code()}",
            default => "keyframe {$setting['keyframe']} of {$setting['shot']->code()}",
        } . (($waits = $this->shot->settingWaitsFor()) !== null ? " (not there yet: the keyframes are drawn once {$waits} is ready)" : '');

        return <<<PROMPT
            {$this->documents()}Takeaway: {$takeaway}{$sequence}
            This shot: {$this->shot->code()}
            Kind of shot: {$this->shot->kindOrScene()->value}, {$this->shot->shotSize()->value}
            Drawn: {$this->drawn()}
            Setting: {$settingLine}

            {$others}

            The plan now:
            Storyline: {$this->storyline()}
            {$keyframes}

            The conversation so far:
            {$conversation}
            {$this->waitingFindings()}

            The director now says: {$message}
            PROMPT;
    }
}
