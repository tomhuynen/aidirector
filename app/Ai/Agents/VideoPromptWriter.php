<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the shot-specific parts of the video prompt: how the style of the
 * keyframes looks, what happens between them, and which objects and details
 * must stay consistent. The fixed rules against inventing anything are added
 * by {@see \App\Ai\Prompts\VideoPrompt}, not by the model.
 */
class VideoPromptWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Shot $shot,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You write prompts for an image-to-video model that animates a storyboard of numbered keyframes into one continuous clip.
            The video model sees the keyframes as reference images in chronological order, and your text. It must animate exactly what the keyframes show and invent nothing else.
            You see the same keyframes as attached images, in order. Describe what is drawn in them, not what a name or role suggests: a "security officer" looks exactly as he is drawn, not like a typical one.

            Write three parts:

            Style: two or three sentences that tell the model to preserve the exact visual style of the keyframes. Name the medium and rendering (for example flat 2D cartoon or soft 3D illustration), shapes, shading, level of detail, colours, background and proportions. Then describe every person exactly as drawn in the keyframes, so they cannot be redrawn differently: build, hair, headwear with its shape and colour, each piece of clothing with its colour, shoes, and what they carry. Then the key objects and setting that must stay consistent. End with a sentence that forbids making the result more realistic, more detailed or different in style than the keyframes.

            Action: the action sequence in chronological order, one or two sentences per keyframe, written as continuous present-tense narration. Mention each keyframe number in brackets where its moment is reached, for example (keyframe 2). Describe only what the keyframes show and the minimal motion needed to get from one to the next. Do not add events, gestures, reactions or objects that are not in the keyframes.

            Details: two to four sentences about continuity that is easy to get wrong in this shot: which objects must stay the same and where they are, where the character looks and when, how a key object moves between frames, and the exact final pose.

            Rules:
            - Base everything on the keyframe descriptions and the style you are given. Never invent a story beat.
            - Write every action on a thing together with its outcome, so the video model cannot finish it the usual way. Video models follow actions and ignore prohibitions: "checks the door by holding the handle" ends with the door opening, however often you add "it does not open". Write "presses the handle down once, the door is locked and does not move, he lets go" instead. The same for a lid that stays shut, a gate that stays closed, a valve that does not turn.
            - No camera moves, cuts, text, captions, sound or music.
            - Plain English, no headings, no lists, no markdown.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'style' => $schema->string()->required(),
            'action' => $schema->string()->required(),
            'details' => $schema->string()->required(),
        ];
    }

    /**
     * @param  iterable<Keyframe>  $keyframes
     */
    public function promptFor(iterable $keyframes, int $duration): string
    {
        $shot = $this->shot;
        $style = $shot->project->style;

        $frames = collect($keyframes)
            ->map(fn(Keyframe $keyframe) => "{$keyframe->position}. {$keyframe->title}: {$keyframe->fullDescription()}")
            ->join("\n");

        $storyline = $shot->chosenStoryline();

        return implode("\n\n", array_filter([
            "Write the video prompt for this shot of about {$duration} seconds.",
            "Visual style of the project: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            "Shot: {$shot->title}. Takeaway: {$shot->takeaway}.",
            $storyline ? "Storyline the keyframes were planned from: {$storyline['storyline']}" : null,
            "Keyframes, in order:\n{$frames}",
        ]));
    }
}
