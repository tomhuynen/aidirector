<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\PurposeBrief;
use App\Ai\Contracts\HasReasoningEffort;
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
            Aspect ratio: {$this->shot->aspectRatio()->value}. Shot length: about {$this->shot->durationInSeconds()} seconds.

            Cast and sets of this project, recurring people, places and objects:
            {$project->elementsBrief()}
            Reuse one of these when this shot is about that person, place or object, and then call it by its exact name and describe it with its description word for word. Introduce new people, places or objects whenever the story needs someone or something else; never force an existing one into a story where it does not belong.

            Rules:
            - Produce between {$min} and {$max} keyframes. Use the fewest that tell the story clearly.
            - Title: two to four words naming the moment.
            - Description: one or two sentences describing exactly what is visible, in present tense. Name the subject, the pose, the key object and its state.
            - Prompt: a self-contained brief for an image model that renders this keyframe on its own, 50 to 90 words. Describe the subject's appearance in full (age, build, hair, clothing) and repeat that wording in every keyframe, then the setting and the backdrop directly behind the character with the context objects on it and where they are (use the same wording for these in every keyframe), the pose, the expression and the state of the key objects. Present tense, concrete nouns, no style words: the visual style is added separately.
            - Elements: the exact names of the cast and sets listed above that are visible in this keyframe. Leave the list empty when none of them appear.
            - Keep the same subject, environment and objects across all keyframes. Do not introduce new characters or props that the storyline does not imply.
            - Staging, because the video model animates cleanest this way: the character stands in the foreground in front of one calm, even backdrop surface that fills the area directly behind them from head to feet, such as a building facade, the side of a container, a fence panel, a wall, or open sky or water. Nothing crosses or touches the figure: no railings, pillars, poles, barriers or machines behind or in front of the character. The wider setting, indoors or outdoors, may be visible around and above that backdrop and in the distance, such as buildings, cranes, a quay or the sky, kept simpler than the character. Context the story needs, such as a sign, sits on the backdrop to one side of the character, clearly readable and not touching them; use at most two such objects. The ground near the character's feet is plain. The setting, the backdrop, the context objects and the camera stay identical in every keyframe, and the character never walks behind anything.
            - Props: use as few hand-held objects as the story needs, ideally one per character. Leave out anything that does not change what the viewer learns, such as a lighter when the point is putting the cigarette away.
            - Readable props, because the video model can only animate what it can read and turns an unclear object into a copy of the main one: every object the story needs is large enough to recognise at a glance and has a colour that stands out from the clothing and backdrop behind it. Hold it away from the body, clear of other objects. When a character holds two objects, keep the hands apart and make the objects clearly different in shape and colour. Say in the description and the prompt which hand holds which object, and keep it in that hand in every keyframe unless the story moves it.
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

        $brief = <<<BRIEF
            Shot title: {$shot->title}
            Subject: {$shot->subject}
            Action: {$shot->action}
            Takeaway: {$shot->takeaway}
            BRIEF;

        if (filled($shot->notes)) {
            $brief .= "\nNotes from the director: {$shot->notes}";
        }

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
}
