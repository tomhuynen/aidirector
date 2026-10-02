<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\PurposeBrief;
use App\Ai\Contracts\HasReasoningEffort;
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
            Your job is to break the shot's storyline into keyframes.
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
            Reuse one of these when this shot is about that person, place or object, and then call it by its exact name. Never describe how one of these looks: the image model draws each from its picture. The people in the shot always come from this list; never introduce a new person. Introduce a new place or object only when the story needs it; never force an existing one into a story where it does not belong.

            Rules:
            - Produce between {$min} and {$max} keyframes. Use the fewest that tell the story clearly.
            - Title: two to four words naming the moment.
            - Description: one or two sentences describing exactly what is visible, in present tense. Name the subject, the pose, the key object and its state.
            - Prompt: a brief for an image model that renders this keyframe, 40 to 80 words. Call the cast and sets by their exact names and never describe their appearance, such as age, build, hair, clothing or colours; their pictures decide that. Describe the spot in the place with the context objects on it and where they are, and any object that is not in the cast and sets (use the same wording for these in every keyframe), then each person's pose, gaze and expression, which hand holds what, and the state of the key objects. Present tense, concrete nouns, no style words: the visual style is added separately.
            - Elements: the exact names of the cast and sets listed above that are visible in this keyframe. Leave the list empty when none of them appear.
            - Keep the same subject, environment and objects across all keyframes. Do not introduce new characters or props that the storyline does not imply.
            - Framing: choose one shot size for the whole shot by what it has to communicate; the camera does not move, so every keyframe shares it. Frame the action: the people and the object they act on, such as a door, a bin or a sign, sit together in the centre of the frame and take most of it. Choose the spot so that object is right beside the person, and keep the rest of the place a simple, subdued background.
            {$this->shotSizes()}
            - Light: the time of day and light the storyline calls for, such as "dusk, low warm evening light, deep blue sky, the torch switched on". Use "as the visual style" when the storyline names no time of day or weather. The light is the same in every keyframe and overrides the lighting of the visual style.
            - Spot: the shot plays at one spot inside the place, seen from where a person would stand there, unless the shot is wide. Pick a calm part of the place that already exists in it, such as the lower part of one hall facade, the side of a container or the quay edge, and describe only what the story needs there, such as the door or the sign the people act on. Do not list background extras such as vehicles, containers, cranes or people just because the place has them. Never invent a separate wall, panel or backdrop in front of the place. Describe the spot with the same wording in every keyframe.
            - Staging, because the video model animates cleanest this way: nothing crosses or touches a figure, so no railings, pillars, poles, barriers or machines directly behind or in front of the people. Context the story needs, such as a sign, sits on the surface at the spot to one side of the people, clearly readable and not touching them; use at most two such objects. The ground near the feet is plain. The spot, the context objects and the camera stay identical in every keyframe, and nobody walks behind anything.
            - Props: use as few hand-held objects as the story needs, ideally one per character. Leave out anything that does not change what the viewer learns, such as a lighter when the point is putting the cigarette away.
            - Readable props, because the video model can only animate what it can read and turns an unclear object into a copy of the main one: every object the story needs is large enough to recognise at a glance and has a colour that stands out from the clothing and the surface behind it. Hold it away from the body, clear of other objects. When a character holds two objects, keep the hands apart and make the objects clearly different in shape and colour. Say in the description and the prompt which hand holds which object, and keep it in that hand in every keyframe unless the story moves it.
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
                    'prompt' => $schema->string()->required(),
                    'elements' => $schema->array()->items($schema->string())->required(),
                ]))
                ->min(Config::get('pipeline.keyframes.min'))
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

        $brief = "Shot title: {$shot->title}\n{$shot->brief()}";

        if ($chosen = $shot->chosenStoryline()) {
            $brief .= "\n\nChosen storyline ({$chosen['title']}): {$chosen['storyline']}";
        }

        if (blank($instruction) || $shot->storyline === null) {
            return "Break this shot into keyframes.\n\n{$brief}";
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
}
