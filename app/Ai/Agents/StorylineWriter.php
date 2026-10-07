<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\PurposeBrief;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ShotKind;
use App\Enums\ShotSize;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns the chosen storyline of a shot into keyframes: an ordered list of
 * clearly readable moments the image and video models can work from.
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
        $min = Config::get('pipeline.keyframes.min');
        $max = Config::get('pipeline.keyframes.max');

        return <<<INSTRUCTIONS
            You are an experienced film director planning a single shot for an animated production.
            Your job is to write the storyline of the shot from the director's takeaway and context, and to break it into keyframes.
            Each keyframe is one clearly readable moment: a pose, a position, an object state.
            The video model will interpolate the motion between keyframes, so keyframes must describe states, not motion.

            {$this->purposeBrief()}

            Project: {$project->title}
            Project description: {$project->description}
            Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.
            Aspect ratio: {$this->shot->aspectRatio()->value}.
            {$this->lengthRule()}

            Cast and sets of this project, recurring people, places and objects:
            {$project->elementsBrief()}
            {$this->projectRules()}
            Reuse one of these when this shot is about that person, place or object, and then call it by its exact name. Never describe how one of these looks: the image model draws each from its picture. The people in the shot always come from this list; never introduce a new person. Introduce a new place or object only when the story needs it; never force an existing one into a story where it does not belong.

            {$this->kindRules()}

            The silent story, the most important rule: the shot plays without words, voice-over, sound or text, so the pictures alone must carry the takeaway.
            - Talking, explaining, welcoming, smiling, nodding, listening, agreeing, signalling or acknowledging never carry the takeaway; they may only go with the act.
            - Test every keyframe: someone who sees only this image, with the sound off, can say what happens in it. Test the row: someone who sees only the images in order can say the takeaway. When they cannot, change the act or add a keyframe for the missing step.

            Rules for every shot:
            - Shot title: two to four words naming the scene, such as the place, the person or the moment.
            - Storyline: two to four sentences, in present tense, from beginning to end, that land the takeaway with one clear, visible action a viewer can follow in the shot length, not a still moment; a montage has its own rule below. Refer to the cast and sets by their names, used as a noun with "the". When the director names people, places or objects they want in the shot, use all of them. When a chosen storyline is given, keep it as it is.
            - Produce between {$min} and {$max} keyframes, except for a presenter shot. Use as many as the silent story above needs and no more: every step a viewer must see to understand it without words gets its own keyframe.
            - Title: two to four words naming the moment.
            - Description: exactly what is visible in this keyframe, two to four sentences, 30 to 70 words, in present tense. It goes to the image model as written and the check judges the image by it. Call the cast and sets by their exact names and never describe their appearance, such as age, build, hair, clothing or colours; their pictures decide that. Describe the spot with the context objects on it and where they are, and any object that is not in the cast and sets (use the same wording for these in every keyframe), then each person's pose, gaze and expression, which hand holds what, and the state of the key objects. Say which way each person faces from the camera's point of view: face to the camera, back to the camera, or side-on facing left or right in the frame, and whether they move towards or away from the camera or to the left or right of the frame. Never write "forward", "looking forward", "ahead" or "angled into": the image model then draws them facing the viewer. Concrete nouns, no style words: the visual style is added separately.
            - Elements: the exact names of the cast and sets listed above that are visible in this keyframe. Leave the list empty when none of them appear.
            - Props: use as few hand-held objects as the story needs, ideally one per character. Leave out anything that does not change what the viewer learns, such as a lighter when the point is putting the cigarette away.
            - Readable props, because the video model can only animate what it can read and turns an unclear object into a copy of the main one: every object the story needs is large enough to recognise at a glance and has a colour that stands out from the clothing and the surface behind it. Hold it away from the body, clear of other objects. When a character holds two objects, keep the hands apart and make the objects clearly different in shape and colour. Say in the description which hand holds which object, and keep it in that hand in every keyframe unless the story moves it.
            - Light: the time of day and light the storyline calls for, such as "dusk, low warm evening light, deep blue sky, the torch switched on". Use "as the visual style" when the storyline names no time of day or weather. The light is the same in every keyframe and overrides the lighting of the visual style.
            - Framing: choose one shot size for the whole shot by what it has to communicate; every keyframe shares it.
            {$this->shotSizes()}
            {$this->sceneRules()}{$this->montageRules()}{$this->presenterRules()}
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
            'storyline' => $schema->string()->required(),
            'framing' => $schema->object([
                'size' => $schema->string()->enum(array_column(ShotSize::cases(), 'value'))->required(),
                'spot' => $schema->string()->required(),
                'light' => $schema->string()->required(),
                'seconds' => $schema->integer()->required(),
            ])->required(),
            'keyframes' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                ]))
                // A presenter shot has one keyframe; the rules set the count for the other kinds.
                ->min(1)
                ->max(Config::get('pipeline.keyframes.max'))
                ->required(),
        ];
    }

    /**
     * The prompt for a fresh keyframe plan, or a revision of the current one.
     */
    public function promptFor(?string $instruction = null): string
    {
        $shot = $this->shot;

        $brief = (filled($shot->title) ? "Shot title: {$shot->title}\n" : '') . $shot->brief();

        if ($chosen = $shot->chosenStoryline()) {
            $brief .= "\n\nChosen storyline ({$chosen['title']}): {$chosen['storyline']}";
        }

        if (blank($instruction) || $shot->storyline === null) {
            return "Write the storyline of this shot and break it into keyframes.\n\n{$brief}";
        }

        $current = collect($shot->storylineKeyframes())
            ->map(fn(array $keyframe, int $index) => ($index + 1) . '. ' . $keyframe['title'] . ': ' . $keyframe['description'])
            ->join("\n");

        return <<<PROMPT
            Revise the current keyframes for this shot. Keep what works and apply the requested change.

            {$brief}

            Current keyframes:
            {$current}

            Requested change: {$instruction}
            PROMPT;
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
            ->prepend('The kind of shot, choose one by the takeaway and give it as the kind:')
            ->join("\n");
    }

    private static function kindLine(ShotKind $kind): string
    {
        return match ($kind) {
            ShotKind::SCENE => 'A scene plays at one place with a camera that does not move: every keyframe is drawn on the same empty place and the video moves through the keyframes.',
            ShotKind::MONTAGE => 'A montage is a row of separate stills, each its own place and moment: every still is drawn on its own, animated with a small movement and joined to the next with a crossfade.',
            ShotKind::PRESENTER => 'A presenter shot is one person from the cast who speaks the voice-over straight to the camera, lip-synced, from the chest up in front of a softly blurred place.',
        };
    }

    /**
     * The rules for a scene, unless the shot is a montage.
     */
    private function sceneRules(): string
    {
        if ($this->shot->kind !== null && $this->shot->kind !== ShotKind::SCENE) {
            return '';
        }

        $heading = $this->shot->kind === null ? "\nFor a scene:\n" : "\n";

        return $heading . <<<'RULES'
            - Before writing, turn the takeaway into one concrete act a viewer can see: a person does something to an object, a place or another person that changes its state, such as putting on a helmet, closing a gate, stopping behind a line or handing over safety glasses. An abstract takeaway still becomes such an act; "safety is our first priority" becomes, for example, the host checking the visitor's helmet strap and handing over safety glasses before the barrier opens.
            - The keyframes go from before, through the act, to after: the first shows the state before, the last shows the result, and the two differ at a glance, such as an object in other hands, a gate open, a person on the other side of a line. Two keyframes that differ only by a gesture or an expression tell nothing new.
            - End every description with the one spatial fact the story depends on in this keyframe, in one concrete sentence a viewer could check at a glance: where the person is relative to the hazard, the line, the door or the object, with a sense of distance, such as "Both feet are clearly behind the yellow line, the container hanging just beyond it, about an arm's length from her."
            - When the story is about a danger, stage the person and the danger close together in the same frame, seen from the side, with the line, gap or route between them clearly visible, so the distance can be read. Never leave the danger small and far behind the person.
            - Keep the same subject, environment and objects across all keyframes. Do not introduce new characters or props that the storyline does not imply.
            - Framing in a scene: the camera does not move. Frame the action: the people and the object they act on, such as a door, a bin or a sign, sit together in the centre of the frame and take most of it. Choose the spot so that object is right beside the person, and keep the rest of the place a simple, subdued background.
            - Keyframe 1 sets the camera for the whole shot: every later keyframe is drawn on top of it. When keyframe 1 shows the place before the people arrive, frame it for them anyway: the spot where they will stand is in the middle foreground, at a scale where an adult standing there fills about two thirds of the frame height, and the object they act on is beside that spot, large enough to read. Say so in its description, such as "the empty spot in front of the bin, framed so a person standing there fills two thirds of the frame height". Never frame an empty keyframe 1 as a wide view of the building.
            - Every step the storyline names gets its own keyframe; nothing important happens between two keyframes. When someone enters a place that lies behind the doorway, show it: a keyframe that says literally "seen from behind, back to the camera, walking away from the camera through the doorway", before a keyframe without them. Someone who leaves towards the camera walks towards it, face to the camera.
            - Spot: the shot plays at one spot inside the place, seen from where a person would stand there, unless the shot is wide. Pick a calm part of the place that already exists in it, such as the lower part of one hall facade, the side of a container or the quay edge, and describe only what the story needs there, such as the door or the sign the people act on. Do not list background extras such as vehicles, containers, cranes or people just because the place has them. Never invent a separate wall, panel or backdrop in front of the place. Describe the spot with the same wording in every keyframe.
            - Staging, because the video model animates cleanest this way: nothing crosses or touches a figure, so no railings, pillars, poles, barriers or machines directly behind or in front of the people. Context the story needs, such as a sign, sits on the surface at the spot to one side of the people, clearly readable and not touching them; use at most two such objects. The ground near the feet is plain. The spot, the context objects and the camera stay identical in every keyframe, and nobody walks behind anything.
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
            - Every keyframe is a still: one part of the takeaway, shown by someone or something in action in its own setting, such as a designer drawing a hull on a large drawing table, welders joining a hull section in a hall, a crane lowering a propeller onto a ship in dry dock, or the finished ship at sea. The stills in order tell the takeaway; each shows one part of it that a viewer recognises without words.
            - Never explain with a board, poster, screen, chart or display of pictograms in the frame: show the thing itself.
            - Storyline: one sentence per still, in order.
            - Description of a still: start with its setting in one sentence, where it is and the one or two objects that matter there, then the people with their pose, which way they face from the camera's point of view and which hand holds what. Leave out a person in a still that is about a place or an object. Describe a moment in which something can move a little, such as sparks, a turning crane hook, a hand drawing, water or a flag, because each still is animated with a small movement, not a sequence of actions.
            - The people from the cast keep their names in every still they are in; use the same light in every still unless the story moves through the day.
            - Spot: leave it empty; every still names its own setting.
            - Seconds: about 2 to 3 per still, between the shortest and the longest length allowed.
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
            - Exactly one keyframe: the still the presenter speaks from. The silent-story rule does not apply: the presenter says the takeaway.
            - The presenter is one person from the cast, such as a host or a manager; the place behind them is a place from the cast. Give both as its elements.
            - Description: the person, from the chest up, facing the camera straight on and looking into the lens, with a friendly, calm expression and the mouth closed; the face large and clear, about a third of the frame height. Behind them the place, softly out of focus and calm, with nothing behind the head that draws attention. Nobody else in the frame.
            - Storyline: one sentence saying who tells the viewer what, and where.
            - Seconds: the time the voice-over takes to say, between the shortest and the longest length allowed.
            RULES;
    }

    /**
     * The shot sizes the planner chooses from, each with when it fits.
     */
    private function shotSizes(): string
    {
        return collect(ShotSize::cases())
            ->map(fn(ShotSize $size) => "  - {$size->value}: when {$size->useWhen()}.")
            ->join("\n");
    }

    /**
     * The director's length when set; otherwise the planner times the action.
     */
    private function lengthRule(): string
    {
        $min = (int) Config::get('pipeline.video.min_duration');
        $max = (int) Config::get('pipeline.video.max_duration');

        if ($this->shot->duration !== null) {
            $seconds = Shot::clampSeconds($this->shot->duration);

            return "Shot length: the director set {$seconds} seconds. Plan for that length and give {$seconds} as the seconds.";
        }

        return <<<RULE
            Shot length: choose it yourself as the seconds. Time each step from one keyframe to the next with these rules of thumb and add them up, plus one second to hold the last pose:
              - a glance, a nod or a turn of the head: 1 second
              - a hand gesture such as pointing, waving or a thumbs up: 1 second
              - picking up, handing over or putting down an object: 1 to 2 seconds
              - opening or closing a door, a lid or a buckle: 1 to 2 seconds
              - a few steps: 2 seconds; walking past or through a space: 3 to 4 seconds
              - putting on or taking off clothing or gear: 3 to 4 seconds
            Round to whole seconds, between {$min} and {$max}.
            RULE;
    }

    /**
     * Rules the director confirmed from recurring corrections, when there are any.
     */
    private function projectRules(): string
    {
        $rules = $this->shot->project->rulesBrief();
        $shotRules = $this->shot->rulesBrief();

        return ($rules === '' ? '' : "\nRules the director confirmed for this project, always follow them:\n{$rules}")
            . ($shotRules === '' ? '' : "\nRules the director set for this shot; the storyline and every keyframe follow them, never break one:\n{$shotRules}");
    }
}
