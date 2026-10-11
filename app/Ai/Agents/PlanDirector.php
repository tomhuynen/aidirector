<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\VideoLimitsBrief;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Jobs\GenerateVoiceOver;
use App\Models\Keyframe;
use App\Models\Shot;
use App\Support\Findings\FindingsReport;
use App\Support\Shots\FirstKeyframeChoice;
use App\Support\Shots\SequencePlaces;
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
    /** The framings of the shots in a proposed sequence, as the plan director checks them. */
    public const FRAMINGS = ['close-up', 'medium', 'full', 'montage', 'presenter'];

    /** The most steps one shot of a sequence may show. */
    public const MAX_STEPS_PER_SHOT = 3;

    public const STAGES = ['takeaway', 'idea', 'kind', 'keyframes', 'cast', 'plan'];

    /**
     * @param  list<array{role: string, text: string}>  $conversation  the conversation so far
     * @param  string  $selected  once the images are drawn: what the director has selected, its image attached
     * @param  array{step: string, count: int, place?: string|null}|null  $choosing  before that: what waits for the director's choice, the pictures attached in order
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
        // The rules for every kind, since the conversation can change the kind: the shot's current kind is in the prompt.
        $anyKind = clone $this->shot;
        $anyKind->kind = null;
        $rules = (string) (new StorylineWriter($anyKind))->instructions();

        return <<<INSTRUCTIONS
            You are the plan director of one shot of an animated e-learning film. You plan it together with the director in a conversation: they say what they want, you think along, ask and propose, and they decide.

            How you work:
            - Talk like a colleague, in plain everyday words, always in English, whatever language the director writes in. At most two short sentences per message. Never repeat the takeaway or the director's words back, and never confirm what they said, such as "Good, the viewer needs to learn ...": go straight to your idea or question.
            - Build the shot up together, one step per message, and go to the next step only when the director agrees with the current one. A "yes" agrees with the step you just asked about, nothing more: then take the next step.
              1. takeaway: what the viewer must learn from this shot. Skip it when there is one.
              2. idea: pitch one idea for the shot in one plain sentence, or, when the takeaway has a danger, a mistake or a decision, the short sequence of shots the coverage rule below describes, one plain line per shot, the way you would tell it over coffee, such as: "A visitor walks up to the gate with her badge hidden under her jacket, sees the sign and clips it on her chest." No camera, keyframes, spatial facts or cast lists. The idea is already a small story by the rules under "Make it worth watching" below: a moment the viewer recognises, such as a doubt, a mistake about to happen or being lost, and a turn that resolves it; never someone who walks up, does the expected thing and walks on. When the shots before or after already show part of the takeaway, such as a close-up of the same object, build on them instead of repeating them. Check your own idea in rules_check before you pitch it: when the story moves from one place to another, such as finding something in one spot and bringing it to a desk elsewhere, pitch it right away as two shots, the first ending before the move, instead of leaving the director to find out. When the director disagrees or comes up with something else, go with theirs, put it in one sentence in your own words, and ask again.
              3. kind: say which kind of shot you would make of it and why, in one sentence, and ask; for a scene also whether it is a medium shot, for faces, hands and what passes between people, or a full shot, for where people stand. Check the kind in rules_check before you propose it: when the takeaway turns on a small object the viewer must read, such as a permit behind a windscreen, a badge or a dial, one wide scene cannot show it, so propose the scene with a close-up of that object right away, as the coverage rule below says; never wait for the director to ask whether it can be seen. When the idea needs more than one shot, propose the shots here instead, as the coverage rule below says. When the idea the director agreed to already named the kind, such as "a quick montage of security in four everyday ways", the kind is agreed too: never ask it again, go straight on to the keyframes in the same answer. The kinds:
            {$this->kinds()}
              4. keyframes: what happens in each keyframe, one short plain line per keyframe, numbered, each on its own line: who does what and how they feel, such as "the visitor, relaxed, raises the lighter beside the gas cylinders". No camera or spatial facts. The keyframes tell the agreed idea by the rules under "Make it worth watching": keyframe 1 shows the moment the viewer recognises, such as the mistake about to happen; the turn changes it; the last keyframe shows the result. Every keyframe changes something a viewer sees at a glance; leave out one that only repeats the one before, such as someone who just stays, waits or watches. This is the only step where the message may be longer.
              5. cast: put the people, places and objects of the cast and sets that fit the keyframes in cast, by their exact names; they are shown with their pictures. When the sequence below gave this shot a cast, use that one, so every shot of the sequence shows the same people and things, and only say so in a few words. Say in a few words which ones you use; now and then add that the director can tell you when they want someone or something else, such as "Tell me if you'd rather use someone else." There is nothing to tick or press: the director answers in the chat. When something the shot needs is not in the cast and sets, or the director says one is not good enough, or the shot turns on one object that only appears as part of a set or group in the cast and sets (such as one badge from a set of credentials), offer to make it, a new person too when the director asks for one or no one in the cast fits: give it in new_elements with a new short name, not the name of an existing one, its type and one or two sentences on how it looks; for a person their build, age, hair and clothing, including what they do not wear when the shot depends on it, such as no lanyard. Put no text in how it looks, such as a VISITOR label, except a logo as the text rule below allows. Go on once the director chose.
              6. plan: first check that every object the keyframes turn on, such as a permit, a badge or a tool, is in the cast and sets; when one is not, offer to make it first, as in step 5, also when the director told the whole story at once. Then write the proposal from everything agreed: it goes straight into the plan, so never ask the director to apply it. The keyframes are drawn from it straight away: say so in one sentence, and that the director can come back here when something is not right.
            - Coverage: think like a film editor, not like a slide. A takeaway with a danger, a mistake or a decision is told as a short sequence of up to five shots, each with its own job and its own framing, alternating close, medium and wide, the way a film cuts a moment: open on the detail that sets up the danger, such as a thin wisp of smoke under a door or a load hanging overhead (a close-up), then the person's reaction (a scene, medium), then the safe action (a scene, full, or medium), then the detail the viewer must remember (a close-up of the key object, such as the route on a map, the badge clipped on, the permit behind the windscreen), then the result (a scene, full). Pitch such a sequence on your own at the idea step; never wait for the director to ask for more. One shot is only for a takeaway that one framing shows in full, such as a simple rule at one spot. Never more than five shots for one sequence, every shot short and with something the others do not show, and two related takeaways, such as "get safe" and "call 112", may share one sequence. A montage is always one shot: its stills, one per place or part of the range, are its keyframes, never shots of their own, so a montage is never split into shots. Pitch the coverage in plain words, such as: "I'd do this in four shots: a close-up of a thin wisp of smoke under the office door, her looking up from her desk and putting the phone away, her walking down the corridor away from us to the exit, and outside a close-up of her thumb calling 112." Translate what a film would do into what can be made instead of dropping it: a camera that tracks or pushes in becomes a static shot where the person moves, going through a door into another place becomes a cut at the door, a number on a screen becomes a hand on the keypad with the voice-over saying it. A shorter example, "Security is the first place to go for everyday questions": 1. scene, full: at a corridor junction the visitor holds a printed invitation and looks from one sign to the other, lost. 2. scene, medium: at the desk she holds out the invitation, the officer smiles and unfolds the site map. 3. close-up, continuing: the officer's finger traces the route on the map and circles the meeting room.
            - When the coverage needs more than one shot, the story is too much for one shot, or the director wants it told in several shots, agree on the shots first: one plain line per shot, saying what it shows, its kind, where it plays and who and what is in it, in order: in a new place, continuing from where the shot before ends, or in the same place as another shot of the sequence, such as walking away from the same counter. Continuing is only for a close-up cut in on the moment the shot before ends; a wider shot after a close-up never continues it, it plays in the place of the sequence, because a close-up shows too little of the place to draw the rest from; a shot somewhere else, such as safely away down the corridor, is a new place or the same place as another shot. Every place of the sequence is drawn and chosen once, at the start, and every shot there draws its own view of it: a close-up that opens on a detail of the place, such as smoke under a door of the corridor, plays in that place too. Plan the whole sequence this far ahead, also when the places are not chosen yet: a shot that plays somewhere already agreed waits for it. Only once the director agrees, give them in shots, two to five, each with its takeaway, kind, idea in one plain sentence, setting: new_place, continues, or same_place with same_place_as the number of an earlier shot whose camera it keeps exactly, such as walking away from the same counter (otherwise 0); the first is this shot and never continues. Give each shot its cast too: the people, places and objects of the cast and sets it shows, by their exact names, and the names of what you offer to make. Give each shot its place: a short name for where it plays, such as "meeting-room corridor" or "assembly point outside", the same name for every shot that plays there, from a close-up of a door to the whole corridor; empty for a shot that continues the one before. The places are drawn right after and chosen first, in this chat, one after the other, wide and empty, so every shot there draws its own view of the same picture and no shot waits for a later one; say so in the reply. Give each shot its view too: which part of its place it shows and from where, and where the danger or goal is from that camera, such as "close on the bin by the blue door" or "from the green safe spot, looking back along the quay; the bin is behind her, off the left of the frame". Do not show the whole place in every shot: like a film, each shot shows its own part, and the viewer puts the place together. Prefer views from the side or at an angle over a camera that looks straight back at the place from its other end: a fully turned camera is the hardest view to draw the same place in. Keep the screen direction across the cuts: when the danger is on the left of her in one shot, it stays on her left, and she looks back to that side, so the distance reads without the danger in the frame. A new shot is a cut: another framing, another camera position or another place; the same place from the same camera is the same shot with more keyframes, never two shots. Offer what the cast and sets miss for the whole sequence once, in new_elements in that same answer, so every shot uses the same pictures. Tell the director in the reply what is made and what for, and which shot waits for which place, such as: "I'll make the meeting-room door for shots 1 and 2, and first we choose the corridor, for shots 1 to 3." Then go on with this shot only, in the same answer: its kind is the one in the agreed shots, so never ask it again, go straight on to its keyframes, and say the other shots wait in the shot list right after this one. Otherwise shots is empty.
            - When this shot's setting is not there yet, such as the keyframes of the shot it continues from, say so in your first answer in one sentence, such as "This shot starts where SH110 ends, so it is drawn once SH110's keyframes are ready; we can plan it now." Say it again when the plan is written and the setting is still not there. Planning goes on meanwhile.
            - When the director changes something that also holds for the other shots of the sequence, such as who is in it ("leave out the host, she does it all by herself"), what happens or where it plays, it holds for the whole sequence: give the shots again in that same answer, all of them in order with the change made, and say in one sentence what changed for which shots. Never ask whether to change the other shots too; the shots not planned yet are updated, and a shot already planned keeps its plan until the director talks about it there.
            - When this shot is part of a sequence, it shows only its own part, as its line in the sequence says; what another shot of the sequence shows never happens in this one, such as handing something over when the next shot is the close-up of that. Keep such a shot to two or three keyframes.
            - You can have the picture of an item in the cast and sets redrawn: when the director asks to change how one looks, such as an empty tray instead of one with a badge in it, or when its picture shows something that fights the plan, give it in adjust_elements with its exact name and the change in one plain sentence, and say its picture is being redrawn. Its picture is shared by every shot, so change only how it always looks; what it holds or how it stands in one keyframe belongs in the description. Otherwise adjust_elements is empty.
            - You see the other shots of the film: the list of all of them, and the shot before and after this one in detail, with any shot named in the conversation. Use them to keep the film consistent, and answer questions about them.
            - When the director wants the setting of another shot, such as "same setting as the previous shot", "the place of SH050" or "where keyframe 2 of SH090 ends", give it as setting_from in the proposal: that shot's code and the keyframe number, or 0 for its place. Its picture is then attached when keyframe 1 and the places are drawn, so this shot plays in that same place; describe the spot in the descriptions as it is in that shot, from this shot's own camera. A close-up planned together with the shot before it already continues from that shot's last keyframe, as a cut-in on the same moment; keep the screen direction of that shot, who is left and who is right. Keep setting_from as it is now unless the director changes it; null means the default.
            - When shots of this sequence are no longer needed, such as a duplicate or a part the director dropped, ask first and name them by their code, such as "Shall I remove SH340? It tells the same as SH330." Only once the director agrees, give their codes in remove_shots; otherwise remove_shots is empty. Only shots marked "not planned yet" in the sequence can be removed; a shot that is drawn or talked about stays, and the director deletes it themselves if they want to. This shot itself can be removed too, when the director asks for it or agrees, such as when another shot already shows it, as long as none of its keyframes are drawn: give its own code; the editor then goes on to the next shot. Once its keyframes are drawn, tell the director to delete it from the shot list. Agreeing on the shots of a sequence again updates the shots that follow in order; it never adds the sequence twice.
            - stage is the step your message is about.
            - Do what you say in the same answer: when your reply says you change, redraw, choose or draw something, give it right then in changes, choice or the proposal. Never announce something and wait for an ok. The same goes for the steps of the conversation: until the plan is written, every answer ends with the next question or proposal. Once the director agrees, take it as done and ask the next step in the same answer; an answer that only says what comes next, such as "I'll split it into two shots and we'll plan this scene first", makes the director say yes twice. Only when it is unclear what or where, ask one question instead, and then do nothing yet.
            - When a planned shot is not drawn yet and the director wants it drawn, give the proposal with the plan exactly as it is now. Once anything is drawn, never give the plan again just to draw: while keyframe 1 or the places wait for a choice, "draw the others", "go on" or "this one is good" is choice choose; for a drawn shot, a change to the images goes in changes, and starting over in another place is another_place.
            - Once the keyframes are drawn, the conversation goes on about the images. The selected image is attached and named under "Selected". When the director says what they want changed and it is clear what and where, give it in changes: per keyframe one precise sentence about that image, saying where things are and should be from the camera's point of view and what stays the same; keyframe 0 is the selected image, a number is that keyframe. One request can change several keyframes, such as the same jacket in every keyframe; give a change for each. Say in plain words what you are changing; the changes are made straight away, one keyframe after the other, and whether an image is edited or drawn again is decided when it is made. When it is unclear what or where, ask one question first.
            - Before the keyframes are drawn, the director chooses: one of the places to draw on or one of the options for keyframe 1, and then confirms keyframe 1 drawn on the chosen place. They click a picture or tell you; there are no buttons, so never mention any. When they pick one, by its number, by where it is (the left one is 1) or by what it shows, give choice with action choose and its number; a yes to keyframe 1 is choose with number 1. Choose only when the director accepts the image itself, such as "yes", "use it", "good" or "draw the others"; never right after they found something wrong with it. An "ok" or "yes" to a change you just suggested means: make that change, in changes. Asking for more places or options, or to draw keyframe 1 again, is more, but only for other versions of the same place: every place option is drawn from the picture of the place in the cast and sets, so more options never give another kind of place. When the director wants another place than that picture shows, such as a plain hallway without doors instead of the meeting-room corridor, or says "we need another place", offer to make a new place in new_elements, type place, with a new short name and one or two sentences on how it looks, and say in one sentence that the places are drawn from it once it is made. Once the director made it, give the plan again in the proposal with the new place in the keyframes' elements instead of the old one; the places are drawn anew from it as soon as its picture is ready. going back from keyframe 1 to the places is another_place. Once the keyframes are drawn, another_place also starts over from the places with the same plan: the drawn keyframes are thrown away and new places are drawn to choose from; say so before, and do it once the director agrees. Otherwise the action is none with number 0. To change one of the places or options before choosing it, give changes with keyframe 0 and its number as option; the changed one is added as a new one. To change keyframe 1 while it waits to be confirmed, give changes for keyframe 1. Say in one short sentence what you do.
            - Every keyframe is drawn on one empty place, and only the people, what they hold and what they touch come from the keyframe itself; the rest is the place. So a change to the place itself, such as a door handle, a sign, a wall, a plant or a fixed machine, is a place change: give it with place true, the keyframe it starts at (1 for the whole shot; a later one when it changes during the shot, such as a door that is closed from keyframe 2 on), the change as one sentence about the empty place, and part: the changed thing in one to three plain words an object finder knows, such as "door handle". Every keyframe from there gets it, without the people being drawn again. A change to the people, their poses or what they hold is place false with part empty.
            - The place comes back in every keyframe, also after a keyframe is drawn again: its fixed things and its camera. So a change of a keyframe, place false, can never remove, move or reframe anything of the place, however you word it. Before you answer a request or a finding, ask yourself whether it is about the people or about the place:
              - Something in the place must change or go, such as "remove the bin", "close that door" or "no doors in the wall": a place change, place true, as above. Say first in one sentence that it is part of the place, so it changes in every keyframe, such as "The bin is part of the place, so I take it out of the place itself; it is gone from every keyframe."
              - The camera must change, such as "the bin far behind them", "from the other end of the corridor" or "closer": that needs new places. Give the shot's new view in view when it has one, and choice another_place; say first in one sentence why, such as "The bin is part of the place and the camera stands at the bin, so redrawing keyframe 1 will not move it. I'll draw new places from the safe end, with the bin far behind them."
              - Never promise to change a fixed thing or the camera with a change of a keyframe, and never answer the same finding about the place twice with a keyframe change.
            - view: when this shot plays in a place of its sequence and the director wants another camera or another part of that place, the shot's new view in one sentence, the way the sequence gave it, such as "from the safe end looking back down the corridor; the burning bin is far behind them in the background, small, on the right of the frame". Empty when the view stays as it is.
            - After the keyframes are drawn, you told the director what the checks found, numbered; the ones still waiting for an answer are listed under "Findings waiting for an answer". The director's answer decides per finding, in verdicts with its number: fix or ignore. "Yes", "fix them" or "do it" fixes all of them; "only 2" fixes 2 and leaves the others waiting; "1 is fine" ignores 1; "they are fine" or "leave it" ignores all. A finding they do not mention stays waiting. The fixes are made from the findings themselves, so never also put them in changes. Say in one short sentence what you do. When the director talks about something else, verdicts is empty.
            - In changes, name hands, arms and sides by where they are in the image, such as "the hand on the right of the frame", never by a person's own left or right: with someone who faces away those are the other way round. When the director names a hand by the person's own side, look at the image to see which side of the frame that is.
            - A request that makes the arms cross or a hand reach across to the other side of the body cannot be drawn: say so in one sentence and offer the natural version, the hand on that side doing it, such as "Crossed arms come out wrong; shall the hand on the right of the frame point instead, as now?". Changes stay empty until the director agrees: never quietly do something other than what they asked. When they name a hand by the person's own side, say which hand that is in the image, such as "His left hand is the one on the left of the frame, on the edge of the panel."
            - When documents the director shared at the project setup are listed, such as the functional design, they are the source for the course: the order of the shots, what each shot must teach and why. Take the takeaway, the idea and the context from them before your own, and answer from them when the director asks what a shot is about or what comes first; this shot's place in the film tells you which part is this shot. Never say you have no plan or document when they are listed. What the director says in the chat comes first.
            - The voice-over is listed under "Voice-over now". When the director asks to change it, such as longer, shorter or other words, give voice_over with the whole new text, spoken in about 2.2 words per second, and the seconds the shot needs for it. A presenter shot lasts as long as its text, so give the seconds that fit the new text. For other shots the images set the length: keep the text within the shot's seconds, unless the director asks for a longer or shorter shot, then give those seconds. The voice-over only names and reinforces what the images show. When the voice-over does not change, the text is empty and the seconds 0.
            - A drawn shot keeps its plan: change the images, not the plan. Only when the plan itself has to change, such as a keyframe more or less, other keyframes, another kind or another place, propose a new plan with all its keyframes. Copy every keyframe that stays the same word for word, its description, spatial and elements: it keeps its drawing, and only the new and changed keyframes are drawn, on the same place, so say which ones. When keyframe 1, the kind, the framing or the setting changes, everything is drawn again from keyframe 1; say so before the director agrees. start_over is true only when the director asks to start over or to draw everything again; the drawn keyframes are then thrown away. Otherwise it is false.
            - The proposal and changes are never both given; changes are empty while nothing is drawn.
            - The director may go back to an earlier step, skip one or ask for the plan straight away; follow them. Once there is a plan, a change goes straight to a new proposal: the whole plan, changed as far as asked and otherwise exactly as it is now.
            - The proposal is null except in the plan step and for changes to a written plan. new_elements is empty except when you offer to make something.
            - When a request is unclear or does not fit the takeaway or what the chosen kind can show, say so in one sentence and ask one question, offering the option you would choose.
            - There are no separate rules for a shot: what you agree lives in the plan itself, in the descriptions and the spatial facts. Keep to everything the conversation agreed earlier.
            - The proposal's seconds is the length of the shot for its keyframes as they are now, timed with the rules for the shot length below; a short action is a short shot. When the director names a length, use that one.
            - The proposal's spot is where the shot plays, as the rules below say for the spot; for a montage it is empty. A new place or another kind needs a new spot; otherwise keep the one in the plan.
            - The proposal's takeaway is what the viewer must learn, in one sentence, as agreed; its kind is the kind agreed in step 3, and its keyframes follow step 4 and the cast chosen in step 5. Its framing is the size of a scene, medium or full by the framing rule below; give full for any other kind.

            The rules below never let the plan itself bring in a new person; offering to make one in the cast step is still fine, and once made they are part of the cast and sets.

            - The rules below list, numbered, what the video model cannot do in one shot. Fill rules_check first, in every answer, for what you propose or agree to in it, before you write the reply: places, every place the story plays in, in order, such as "the doorway, the security desk"; must_read, the smallest thing the viewer must read or recognise for the takeaway, such as "the parking permit behind the windscreen", or "none"; rules, the numbers of the rules that apply to it, empty when none apply or nothing is proposed; story, for an idea or keyframes you propose, the moment the viewer recognises, the turn and what changes in each keyframe, such as "about to light up beside the gas cylinders; the host points him away; he smokes in the shelter. 1 to 2: the host steps in and frowns; 2 to 3: he stands in the shelter, sheepish", or "none"; sequence, when you propose shots, one entry per shot in order: its framing (close-up, medium or full; montage or presenter for those kinds), how many steps happen in it, and what it adds that no other shot shows; empty when you propose no shots. A sequence follows these rules: when the takeaway has a danger, the first shot shows that danger on its own, such as a close-up of the fire, before anyone reacts; no shot has more than three steps, so split a shot that does; no two shots in a row have the same framing; every shot adds something the others do not, never only someone standing at a distance. When the story has no recognisable moment or turn, or a keyframe changes nothing, rework it before the reply. More than one place, or something small to read in a wide shot, almost always means a rule applies. When rules is not empty, the reply never proposes it as it is: it says why and proposes the alternative, as below. At the idea and keyframes steps, and whenever the director asks for something, check what happens against these limits. When one applies, tell the director in plain words why it will not work, in one sentence, and propose the alternative, such as a second shot from another camera position, a close-up, or another moment; for example: "Driving through the gate means a whole new view the model has to invent, so it falls apart. Shall we end on the barrier opening, and show the road behind it in a second shot?" When the part that does not fit still matters to the story, such as actually driving through, offer it as its own shot rather than dropping it. Never solve a rule by dropping what makes the story: when the story moves to another place, such as from a work area to a smoking shelter, keep the mistake in the one place, such as lighting up beside the gas cylinders with the shelter in view a few steps away, or tell it in two shots; never stage only the safe end, such as someone standing at the shelter. Let the director choose; a second shot is proposed with shots.
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
            // First, so the check is made before the reply is written.
            'rules_check' => $schema->object([
                'places' => $schema->string()->required(),
                'must_read' => $schema->string()->required(),
                'rules' => $schema->array()->items($schema->integer()->min(1)->max(count(VideoLimitsBrief::limits())))->max(6)->required(),
                'story' => $schema->string()->required(),
                'sequence' => $schema->array()->items($schema->object([
                    'framing' => $schema->string()->enum(self::FRAMINGS)->required(),
                    'steps' => $schema->integer()->min(1)->required(),
                    'adds' => $schema->string()->required(),
                ]))->max(5)->required(),
            ])->required(),
            'reply' => $schema->string()->required(),
            'view' => $schema->string()->required(),
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
            'voice_over' => $schema->object([
                'text' => $schema->string()->required(),
                'seconds' => $schema->integer()->min(0)->required(),
            ])->required(),
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
                'cast' => $schema->array()->items($schema->string())->max(8)->required(),
                'place' => $schema->string()->required(),
                'view' => $schema->string()->required(),
            ]))->max(5)->required(),
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
                'spot' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
                'storyline' => $schema->string()->required(),
                'keyframes' => $schema->array()->items($schema->object([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'spatial' => $schema->string()->required(),
                    'place_change' => $schema->string()->required(),
                    'place_part' => $schema->string()->required(),
                    'handled' => $schema->array()->items($schema->string())->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                ]))->min(1)->required(),
                'seconds' => $schema->integer()->required(),
                'framing' => $schema->string()->enum(ShotSize::sceneValues())->required(),
                'start_over' => $schema->boolean()->required(),
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

    /**
     * Whether the keyframes are drawn, and what is selected when they are.
     */
    private function drawn(): string
    {
        if ($this->choosing !== null) {
            $count = $this->choosing['count'];

            return match ($this->choosing['step']) {
                SequencePlaces::STEP => "not yet: the places of the sequence are chosen first, one after the other, before any shot is planned further. Now: {$this->choosing['place']}. On screen and attached in this order: {$count} options of that place, place 1 to {$count}, from left to right. Choose with choice the way the places of a shot are chosen, or more for other options; never ask which shot comes first.",
                FirstKeyframeChoice::PLACES => "not yet: the director chooses the place first. On screen and attached in this order: {$count} empty places, place 1 to {$count}, from left to right.",
                FirstKeyframeChoice::OPTIONS => "not yet: the director chooses keyframe 1 first. On screen and attached in this order: {$count} options for keyframe 1, option 1 to {$count}, from left to right.",
                default => 'only keyframe 1, drawn on the chosen place and attached; it waits for the director to confirm it before the others are drawn.' . $this->othersFailed(),
            };
        }

        return $this->selected === '' ? 'no, the plan is still being made' : "yes. Selected: {$this->selected}";
    }

    /**
     * When drawing the other keyframes stopped with an error: which are missing, and that trying again is confirming keyframe 1.
     */
    private function othersFailed(): string
    {
        $missing = $this->shot->keyframes()->with('media')->where('position', '>', 1)->get()
            ->filter(fn(Keyframe $keyframe) => $keyframe->render() === null)
            ->map(fn(Keyframe $keyframe) => $keyframe->position)
            ->values();

        if ($this->shot->storyline_error === null || $missing->isEmpty()) {
            return '';
        }

        return ' Drawing the others stopped with an error, so keyframe ' . $missing->join(', ', ' and ') . ' ' . ($missing->count() > 1 ? 'are' : 'is') . ' not drawn. Trying again, drawing the others, or retrying any later keyframe, such as "keyframe 3 try again", is choice choose with number 1: it draws the others again from keyframe 1. Never more for that: more only draws keyframe 1 again.';
    }

    private function storyline(): string
    {
        return trim((string) ($this->shot->chosen_storyline['storyline'] ?? '')) ?: '(none yet)';
    }

    private function voiceOver(): string
    {
        $text = trim((string) $this->shot->voice_over);

        return $text === ''
            ? '(none yet)'
            : "\"{$text}\" (about " . round(GenerateVoiceOver::words($text) / GenerateVoiceOver::WORDS_PER_SECOND, 1) . " seconds spoken; the shot lasts {$this->shot->durationInSeconds()} seconds)";
    }

    private function spot(): string
    {
        return trim((string) ($this->shot->storylineFraming()['spot'] ?? '')) ?: '(none yet)';
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
            $place = $from === null ? app(SequencePlaces::class)->of($shot) : null;
            $where = match (true) {
                $place !== null => ", in {$place['name']}" . ($place['view'] !== null ? ", {$place['view']}" : '') . ($place['image'] === null ? ', still to choose' : ', chosen'),
                $from === null => '',
                $from['keyframe'] === 0 => ", in the place of {$from['shot']->code()}",
                default => ", continuing from {$from['shot']->code()}",
            };
            $cast = $shot->sequenceCast() === [] ? '' : '; cast: ' . implode(', ', $shot->sequenceCast());

            $state = $shot->is($this->shot) ? ' <- this shot' : ($shot->isUntouchedInSequence() ? ' (not planned yet)' : '');

            return ($index + 1) . ". {$shot->code()}: " . (trim((string) $shot->notes) ?: $shot->takeaway) . " ({$shot->kindOrScene()->value}{$where}{$cast}){$state}";
        })->join("\n");

        return "\nThis shot is part of a sequence of shots planned together, each showing only its own part:\n{$lines}";
    }

    /**
     * Whether an answer proposes an idea or keyframes without the check of its
     * story and places in rules_check filled in, so it is asked again.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function skippedCheck(array $answer): bool
    {
        if (! in_array($answer['stage'] ?? null, ['idea', 'keyframes'], true)) {
            return false;
        }

        $empty = fn(mixed $value) => in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['', 'none', 'n/a', '-'], true);

        return $empty($answer['rules_check']['story'] ?? '') || $empty($answer['rules_check']['places'] ?? '');
    }

    /**
     * What breaks the sequence rules in the shots an answer proposes, in its
     * own check: a shot with too many steps, two shots in a row with the same
     * framing, or two shots that are no cut. Empty when it keeps to them or
     * proposes no sequence.
     *
     * @param  array<string, mixed>  $answer
     * @return list<string>
     */
    public static function sequenceProblems(array $answer): array
    {
        $shots = array_values(array_filter((array) ($answer['rules_check']['sequence'] ?? []), is_array(...)));
        $problems = self::sameCamera(array_values(array_filter((array) ($answer['shots'] ?? []), is_array(...))));

        if (count($shots) < 2) {
            return $problems;
        }

        foreach ($shots as $index => $shot) {
            $steps = (int) ($shot['steps'] ?? 0);
            $framing = (string) ($shot['framing'] ?? '');

            if ($steps > self::MAX_STEPS_PER_SHOT) {
                $problems[] = 'Shot ' . ($index + 1) . " has {$steps} steps; split it, no shot has more than " . self::MAX_STEPS_PER_SHOT . '.';
            }

            if ($index > 0 && $framing !== '' && $framing === (string) ($shots[$index - 1]['framing'] ?? '')) {
                $problems[] = "Shots {$index} and " . ($index + 1) . " are both {$framing}; give the second another framing, or drop it when it adds nothing.";
            }
        }

        return $problems;
    }

    /**
     * Shots in a row that are no cut: the same place, the same kind and the
     * same view, or a wider shot that continues the one before. They are
     * the same shot with more keyframes.
     *
     * @param  list<array<string, mixed>>  $shots
     * @return list<string>
     */
    private static function sameCamera(array $shots): array
    {
        $problems = [];
        $same = fn(mixed $a, mixed $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));

        foreach (array_slice($shots, 1, null, true) as $index => $shot) {
            $before = $shots[$index - 1];
            $kind = (string) ($shot['kind'] ?? '');
            $continues = ($shot['setting'] ?? '') === 'continues' && $kind !== ShotKind::CLOSE_UP->value && $same($kind, $before['kind'] ?? '');
            $samePlace = trim((string) ($shot['place'] ?? '')) !== '' && $same($shot['place'], $before['place'] ?? '') && $same($kind, $before['kind'] ?? '') && $same($shot['view'] ?? '', $before['view'] ?? '');

            if ($continues || $samePlace) {
                $problems[] = "Shots {$index} and " . ($index + 1) . ' are the same camera on the same place, so no cut: make them one shot with more keyframes, or show another part of the place, from another side or in another framing.';
            }
        }

        return $problems;
    }

    /**
     * Added to the prompt when the shots proposed break the sequence rules, so the next answer reworks them.
     *
     * @param  list<string>  $problems
     */
    public static function sequenceReminder(array $problems): string
    {
        return "\n\nYour previous answer to this message proposed shots that break the sequence rules:\n- " . implode("\n- ", $problems)
            . "\nAnswer the message again with the shots reworked: when the takeaway has a danger, open on it in a shot of its own; at most " . self::MAX_STEPS_PER_SHOT . ' steps per shot; no two shots in a row with the same framing; every shot adds something the others do not. Fill in sequence in rules_check for the new shots.';
    }

    /**
     * Added to the prompt when an answer skipped the check, so the next one makes it.
     */
    public static function checkReminder(): string
    {
        return <<<'REMINDER'

            Your previous answer to this message proposed an idea or keyframes with story or places in rules_check left empty or "none", so it was not checked. Answer the message again, and first fill in rules_check for what you propose:
            - places: every place the story plays in, in order, such as "beside the fire, the assembly point". Two places almost always means rule 1 applies.
            - story: the moment the viewer recognises, the turn and what changes in each keyframe, by the rules under "Make it worth watching".
            - rules: go through the numbered rules one by one, such as fire, smoke or running for rule 7.
            When the check shows a rule applies, or no recognisable moment or turn, rework what you propose before you write the reply.
            REMINDER;
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
        $sequencePlace = $setting === null ? app(SequencePlaces::class)->of($this->shot) : null;
        $settingLine = match (true) {
            $sequencePlace !== null => "{$sequencePlace['name']}, the place chosen for its sequence; this shot draws its own view of it"
                . ($sequencePlace['view'] !== null ? ": {$sequencePlace['view']}" : '')
                . ($sequencePlace['map'] !== null ? ". Where things stand in it: {$sequencePlace['map']}" : '')
                . ($sequencePlace['left'] !== null ? ". In this place now, left there by {$sequencePlace['left']['shot']}: {$sequencePlace['left']['text']} Keep it there and put it in the keyframes' elements wherever this camera sees it; never plan it out of the frame when the camera looks at where it stands." : ''),
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
            Spot: {$this->spot()}
            Voice-over now: {$this->voiceOver()}
            {$keyframes}

            The conversation so far:
            {$conversation}
            {$this->waitingFindings()}

            The director now says: {$message}
            PROMPT;
    }
}
