<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\PurposeBrief;
use App\Ai\Briefs\StagingRules;
use App\Ai\Briefs\TextRules;
use App\Ai\Briefs\VideoLimitsBrief;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Models\Shot;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Plans a shot from the director's idea: the storyline, and the keyframes
 * the image and video models work from. A scene or close-up has two: the
 * start frame, the state before, and the end frame, the result; the video
 * plays the act between them. A montage has one still per part. The plan
 * director writes its plans by these same rules.
 */
class StorylineWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
    ) {}

    public function instructions(): Stringable|string
    {
        $project = $this->shot->project;
        $style = $project->style;
        $max = Config::get('pipeline.keyframes.max');
        $limits = VideoLimitsBrief::brief();
        $staging = StagingRules::rules();

        return <<<INSTRUCTIONS
            You are an experienced film director planning a single shot for an animated production.
            Your job is to write the storyline of the shot from the director's idea, and to give its keyframes: for a scene or a close-up the start frame and the end frame, for a montage one still per part.
            Each keyframe is one clearly readable moment: a pose, a position, an object state.
            The video model plays the act from the start frame to the end frame, so keyframes describe states, not motion.

            {$this->purposeBrief()}

            Project: {$project->title}
            Project description: {$project->description}
            Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.
            Aspect ratio: {$this->shot->aspectRatio()->value}.
            {$this->lengthRule()}

            Cast and sets of this project, recurring people, places and objects:
            {$project->elementsBrief()}
            {$this->projectRules()}

            Text in the images: {$this->textRule()}
            Reuse one of these when this shot is about that person, place or object, and then call it by its exact name. Never describe how one of these looks: the image model draws each from its picture. The people in the shot always come from this list; never introduce a new person. One role in the shot is played by one person: when several people in the cast fit it, such as two security staff, choose one and use that same name in every keyframe; never switch between them. Introduce a new place or object only when the story needs it; never force an existing one into a story where it does not belong. Give every new place or object the story turns on, such as a design office, a parking permit or a damaged badge, in new_elements with a short name, its type and one or two sentences on how it looks, concrete enough to draw it the same way every time, following the text rule below; then call it by that exact name in the keyframes and list it in the elements of the keyframes that show it. Never present such an object as part of another item in the cast and sets, such as "the parking permit from the Access credentials set": the image model then draws what that item's picture shows instead. When the cast and sets have no picture of the object itself, it is a new element of its own. Leave new_elements empty when the cast and sets have everything.

            {$this->kindRules()}

            What the video model cannot do in one shot, each with why it fails and what to do instead. Plan every shot around these:
            {$limits}

            The silent story, the most important rule: the shot plays without words, voice-over, sound or text, so the pictures alone must tell the storyline.
            - Talking, explaining, welcoming, smiling, nodding, listening, agreeing, signalling or acknowledging never carry the story; they may only go with the act.
            - Test every keyframe: someone who sees only this image, with the sound off, can say what happens in it. Test the pair: someone who sees only the start frame and the end frame can tell the storyline. When they cannot, change the act, or tell the story in more shots.

            Rules for every shot:
            - Shot title: two to four words naming the scene, such as the place, the person or the moment.
            - Storyline: two to four sentences, in present tense, from beginning to end, that tell the idea with one clear, visible action a viewer can follow in the shot length, not a still moment; a montage and an insert have their own rule below. Refer to the cast and sets by their names, used as a noun with "the". When the director names people, places or objects they want in the shot, use all of them.
            - A scene or a close-up has exactly two keyframes: the start frame, the state before the act, and the end frame, the result. One act per shot: when the story needs a second act, or a step in between that a viewer must see, it is told in more shots. A montage has one still per part, at most {$max}. A presenter shot or an insert has one keyframe.
            - Title: two to four words naming the moment.
            - Seconds of a keyframe: for the start frame, how long the act takes to reach the end frame, timed with the rules of thumb for the shot length, in halves of a second; for the end frame, how long it is held at the end, 0 when the shot ends as it reaches it. For a montage, how long each still shows. A shot of one keyframe holds it for the whole shot. Together they make the shot's seconds.
            {$staging}
            - Spatial fact: for a scene, as its rule below says; leave it empty for a montage, a presenter shot or an insert.
            - Elements: the exact names of the cast and sets listed above that are visible in this keyframe. Leave the list empty when none of them appear.
            - Continuity, because the video model cannot make anything appear or change by itself, except between the separate stills of a montage: everything the shot uses, people and things, is in the start frame, or visibly comes in, such as walking in or being taken out of a bag or pocket that is in view; nothing appears or disappears between the start frame and the end frame. Both say where each thing the story uses is and in what state: in whose hand, where it lies or hangs, worn or not, open or closed, such as "the Damen host holds the Visitor safety vest folded in his right hand; the blue helmet lies on the bench beside him". The video model plays one change between the two frames: one thing that changes hands, place or state, or one movement. A change that is hard to animate, such as putting on or taking off clothing or gear, or opening a box, is its own shot of at least 3 seconds, and a step a viewer must see halfway, such as one arm in the vest, is a shot of its own too.
            - Props: use as few hand-held objects as the story needs, ideally one per character. Leave out anything that does not change what the viewer learns, such as a lighter when the point is putting the cigarette away.
            - Readable props, because the video model can only animate what it can read and turns an unclear object into a copy of the main one: every object the story needs is large enough to recognise at a glance and has a colour that stands out from the clothing and the surface behind it. Hold it away from the body, clear of other objects. When a character holds two objects, keep the hands apart and make the objects clearly different in shape and colour. Give in the cast which hand holds which object.
            {$this->sceneRules()}{$this->closeUpRules()}{$this->montageRules()}{$this->presenterRules()}{$this->insertRules()}
            - No camera language, no text or captions in frame, no sound.
            - Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'kind' => $schema->string()->enum(array_column(ShotKind::cases(), 'value'))->required(),
            'new_elements' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'type' => $schema->string()->enum(['place', 'object'])->required(),
                'description' => $schema->string()->required(),
            ]))->max(4)->required(),
            'storyline' => $schema->string()->required(),
            'framing' => $schema->object([
                'spot' => $schema->string()->required(),
                'seconds' => $schema->integer()->required(),
            ])->required(),
            'keyframes' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    ...KeyframeStaging::schema($schema),
                    'spatial' => $schema->string()->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                    'seconds' => $schema->number()->min(0)->required(),
                ]))
                // A presenter shot or an insert has one keyframe, a scene or close-up two, a montage one per part.
                ->min(1)
                ->max(Config::get('pipeline.keyframes.max'))
                ->required(),
        ];
    }

    /**
     * The prompt for a fresh keyframe plan, or a revision of the current one.
     */
    public function promptFor(): string
    {
        $brief = (filled($this->shot->title) ? "Shot title: {$this->shot->title}\n" : '') . $this->shot->brief();

        return "Write the storyline of this shot and give its keyframes.\n\n{$brief}";
    }

    /**
     * No text on anything, except the logos of the project's branding.
     */
    private function textRule(): string
    {
        return TextRules::forPlanning($this->shot->project);
    }

    private function purposeBrief(): string
    {
        return PurposeBrief::for($this->shot->purpose());
    }

    /**
     * What a scene and a montage are; when the kind is set, only that one, otherwise the planner chooses.
     */
    private function kindRules(): string
    {
        $kind = $this->shot->kind;

        if ($kind !== null) {
            return "This shot is a {$kind->value}: give {$kind->value} as the kind. " . self::kindLine($kind);
        }

        return collect(ShotKind::cases())
            ->map(fn(ShotKind $kind) => "- {$kind->value}: " . self::kindLine($kind) . " Choose it when {$kind->useWhen()}.")
            ->prepend('The kind of shot, choose one by the idea and give it as the kind:')
            ->join("\n");
    }

    private static function kindLine(ShotKind $kind): string
    {
        return match ($kind) {
            ShotKind::SCENE => 'A scene plays at one place with a camera that does not move: the start frame is drawn on the empty place, the end frame on the start frame, and the video plays the act between them.',
            ShotKind::MONTAGE => 'A montage is a row of separate stills, each its own place and moment: every still is drawn on its own, animated with a small movement and joined to the next with a crossfade.',
            ShotKind::PRESENTER => 'A presenter shot is one person from the cast who speaks the voice-over straight to the camera, lip-synced, from the chest up in front of a softly blurred place.',
            ShotKind::CLOSE_UP => 'A close-up is one small hand action with one object, with the camera close and still: the hands and the object fill the frame, on a person such as a badge on the chest, or on a thing such as a permit on a dashboard. The start frame is drawn first with them and the end frame is drawn on it.',
            ShotKind::MEDIUM => 'A medium shot is a scene framed from the waist up: one person with their face and both hands in frame and the object they act on in the lower half, with the camera still. It follows the rules of a scene.',
            ShotKind::INSERT => 'An insert is one still of one thing the viewer must see on its own, such as a sign, a label or a warning, with no people and nothing happening; the camera pushes in slowly while it shows.',
        };
    }

    /**
     * The rules for a scene and a medium shot, unless the shot is another kind.
     */
    private function sceneRules(): string
    {
        if ($this->shot->kind !== null && ! in_array($this->shot->kind, [ShotKind::SCENE, ShotKind::MEDIUM], true)) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor a scene or a medium shot:\n" : "\n";

        return $heading . <<<'RULES'
            - Before writing, turn the idea into one concrete act a viewer can see: a person does something to an object, a place or another person that changes its state, such as putting on a helmet, closing a gate, stopping behind a line or handing over safety glasses. An abstract idea still becomes such an act; "safety is our first priority" becomes, for example, the host checking the visitor's helmet strap and handing over safety glasses before the barrier opens.
            - The start frame shows the state before the act and the end frame the result, and the two differ at a glance, such as an object in other hands, a gate open, a person on the other side of a line. An end frame that differs from the start frame only by a gesture or an expression tells nothing new.
            - Spatial fact: the one spatial fact the story depends on in this keyframe, in one concrete sentence a viewer could check at a glance, kept apart from the place and the cast and never repeated there: where the person is relative to the hazard, the line, the door or the object, with a sense of distance, such as "Both feet are clearly behind the yellow line, the container hanging just beyond it, about an arm's length from her."
            - When the story is about a danger, stage the person and the danger close together in the same frame, seen from the side, with the line, gap or route between them clearly visible, so the distance can be read. Never leave the danger small and far behind the person.
            - Keep the same subject, environment and objects in both frames. Do not introduce new characters or props that the storyline does not imply.
            - Framing in a scene: the camera does not move. Frame the action: the people and the object they act on, such as a door, a bin or a sign, sit together in the centre of the frame and take most of it. Choose the spot so that object is right beside the person, and keep the rest of the place a simple, subdued background.
            - The start frame sets the camera for the whole shot: the end frame is drawn on top of it. When the start frame shows the place before the people arrive, frame it for them anyway: the spot where they will stand is in the middle foreground, at a scale where an adult standing there fills about two thirds of the frame height, and the object they act on is beside that spot, large enough to read. Say so in its place, such as "the empty spot in front of the bin, framed so a person standing there fills two thirds of the frame height". Never frame an empty start frame as a wide view of the building.
            - The storyline names one act, and it happens between the start frame and the end frame; a storyline with two acts, such as noticing something and then leaving, is two shots. When someone enters a place that lies behind the doorway, the end frame says literally "seen from behind, back to the camera, walking away from the camera through the doorway". Someone who leaves towards the camera walks towards it, face to the camera.
            - Spot: the shot plays at one spot inside the place, seen from where a person would stand there, unless the shot is wide. Pick a calm part of the place that already exists in it, such as the lower part of one hall facade, the side of a container or the quay edge, and describe only what the story needs there, such as the door or the sign the people act on. Do not list background extras such as vehicles, containers, cranes or people just because the place has them. Never invent a separate wall, panel or backdrop in front of the place. Describe the spot with the same wording in every keyframe.
            - In a medium shot the person is framed from the waist up: the object they act on is in their hands or just below them, in the lower half of the frame, and their face and both hands stay in frame in both keyframes.
            - Staging, because the video model animates cleanest this way: nothing crosses or touches a figure, so no railings, pillars, poles, barriers or machines directly behind or in front of the people. Context the story needs, such as a sign, sits on the surface at the spot to one side of the people, clearly readable and not touching them; use at most two such objects. The ground near the feet is plain. The spot, the context objects and the camera stay identical in both frames, and nobody walks behind anything.
            RULES;
    }

    /**
     * The rules for a montage, when the shot is one or the planner chooses.
     */
    private function montageRules(): string
    {
        if ($this->shot->kind !== null && $this->shot->kind !== ShotKind::MONTAGE) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor a montage:\n" : "\n";

        return $heading . <<<'RULES'
            - Every keyframe is a still: one part of the idea, shown by someone or something in action in its own setting, such as a designer drawing a hull on a large drawing table, welders joining a hull section in a hall, a crane lowering a propeller onto a ship in dry dock, or the finished ship at sea. The stills in order tell the idea; each shows one part of it that a viewer recognises without words.
            - Never explain with a board, poster, screen, chart or display of pictograms in the frame: show the thing itself.
            - Say which way someone faces only when the still depends on it; a person at work who faces the camera is fine.
            - Every still has its own setting that fits its part; two parts never share a place unless the story says so. When no place in the cast and sets fits a part, give it as a new place in new_elements, such as "Design office": a bright office with a large drawing table under the windows. Never put a part in a place of the cast where it does not happen, such as designing in a shipbuilding hall.
            - Storyline: one sentence per still, in order.
            - Place of a still: its setting in one sentence, where it is and the one or two objects that matter there. Leave out a person in a still that is about a place or an object. Stage a moment in which something can move a little, such as sparks, a turning crane hook, a hand drawing, water or a flag, because each still is animated with a small movement, not a sequence of actions.
            - The people from the cast keep their names in every still they are in; use the same light in every still unless the story moves through the day.
            - Spot: leave it empty; every still names its own setting.
            - Seconds: about 2 to 3 per still, between the shortest and the longest length allowed.
            RULES;
    }

    /**
     * The rules for a close-up, when the shot is one or the planner chooses.
     */
    private function closeUpRules(): string
    {
        if ($this->shot->kind !== null && $this->shot->kind !== ShotKind::CLOSE_UP) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor a close-up:\n" : "\n";

        return $heading . <<<'RULES'
            - The start frame and the end frame of one hand action with one small object, before and after: a badge not yet clipped and then clipped high on the chest, an open padlock and then closed, a cracked badge laid on the counter and then a new one handed over.
            - The object is large, clear and in a colour that stands out; when it is damaged, show it unmistakably, such as snapped in two, never a hairline crack.
            - The before state comes from the object itself: turned the wrong way round, hanging low, open, unclipped or broken. Never hide it behind or inside clothing, such as tucked under a jacket: the image model then invents extra layers of clothing.
            - A person handles their own object, such as their own badge or buckle; nobody else touches their body or clothing.
            - Place: where the action is, such as the chest of her hi-vis vest or the top of the reception counter, then the object and its state. In the cast, the person whose hands or body it is, with stands empty, and what their hands do and hold; show only as much of them as the action needs, such as the chest and hands, never the whole figure.
            - Spatial fact: where the object is relative to the hand, the surface or the body, such as "The badge clip is fastened on the vest pocket, high and centred on the chest."
            - Spot: where the close-up is, such as "her chest, the vest pocket in the middle of the frame" or "the top of the reception counter, beside the badge tray"; the same in every keyframe.
            - Elements: the object, the person or people whose hands show, and the place the surface belongs to.
            - Seconds: about 2 seconds for the hand action, between the shortest and the longest length allowed.
            RULES;
    }

    /**
     * The rules for a presenter shot, when the shot is one or the planner chooses.
     */
    private function presenterRules(): string
    {
        if ($this->shot->kind !== null && $this->shot->kind !== ShotKind::PRESENTER) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor a presenter shot:\n" : "\n";

        return $heading . <<<'RULES'
            - Exactly one keyframe: the still the presenter speaks from. The silent-story rule does not apply: the presenter says it.
            - The presenter is one person from the cast, such as a host or a manager; the place behind them is a place from the cast. Give both as its elements.
            - Place: the person from the chest up, the face large and clear, about a third of the frame height; behind them the place, softly out of focus and calm, with nothing behind the head that draws attention. Nobody else in the frame.
            - Cast: the presenter stands in the centre, faces the camera and looks at the camera, with a friendly, calm expression and the mouth closed.
            - Storyline: one sentence saying who tells the viewer what, and where.
            - Seconds: the time the voice-over takes to say, between the shortest and the longest length allowed.
            RULES;
    }

    private function insertRules(): string
    {
        if ($this->shot->kind !== null && $this->shot->kind !== ShotKind::INSERT) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor an insert:\n" : "\n";

        return $heading . <<<'RULES'
            - Exactly one keyframe: the thing on its own, such as the no-camera sign on its wall. The silent-story rule is met by the thing itself, read at a glance.
            - No people and no hands, unless the director asks for them; nothing moves.
            - Place: the thing and the surface it is on, such as "the no-camera sign on the plain dockside wall", then the place behind it in a few words. Cast: empty.
            - Elements: the thing, and the place it is in.
            - Storyline: one sentence saying what the viewer sees.
            - Seconds: 2 to 3, enough to read it, between the shortest and the longest length allowed.
            RULES;
    }

    /**
     * The director's length when set; otherwise the planner times the action.
     */
    private function lengthRule(): string
    {
        if ($this->shot->duration !== null) {
            $seconds = Shot::clampSeconds($this->shot->duration);

            return "Shot length: the director set {$seconds} seconds. Plan for that length and give {$seconds} as the seconds.";
        }

        return 'Shot length: choose it yourself as the seconds. ' . self::timingRules();
    }

    /**
     * How long a shot lasts for what its keyframes show: the rules of thumb the
     * planner times a new plan with, and a changed plan is timed again with.
     */
    public static function timingRules(): string
    {
        $min = (int) Config::get('pipeline.video.min_duration');
        $max = (int) Config::get('pipeline.video.max_duration');

        return <<<RULE
            The animation acts fast, so keep it short: time the act from the start frame to the end frame with these rules of thumb, with no extra time to hold the end pose:
              - a glance, a nod, a turn of the head or a hand gesture such as pointing or waving: 1 second
              - picking up, handing over or putting down an object: 1 second
              - opening or closing a door, a lid or a buckle: 1 to 2 seconds
              - a few steps: 1 to 2 seconds; walking past or through a space: 2 to 3 seconds
              - putting on or taking off clothing or gear, such as a vest, a jacket or a helmet: 3 to 4 seconds
            Round to whole seconds, between {$min} and {$max}.
            RULE;
    }

    /**
     * Rules the director confirmed from recurring corrections, when there are any.
     */
    private function projectRules(): string
    {
        $rules = $this->shot->project->rulesBrief();

        return $rules === '' ? '' : "\nRules the director confirmed for this project, always follow them:\n{$rules}";
    }
}
