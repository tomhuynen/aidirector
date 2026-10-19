<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\BodyRules;
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
 * frames looks, what happens from the start frame to the end frame, and which
 * objects and details must stay consistent. The fixed rules against inventing
 * anything are added by {@see \App\Ai\Prompts\VideoPrompt}, not by the model.
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
        $tempo = BodyRules::NO_TEMPO;

        return <<<INSTRUCTIONS
            You write prompts for an image-to-video model that plays one shot from its start frame to its end frame in one continuous clip.
            The video model gets the start frame as the first frame of the clip and the end frame as the last, and your text. It must animate exactly the act between them and invent nothing else. A shot with only a start frame holds it.
            You see the same frames as attached images, the start frame first. Describe what is drawn in them, not what a name or role suggests: a "security officer" looks exactly as he is drawn, not like a typical one.

            Write three parts for the video model, and a summary for the director:

            Style: two or three sentences that tell the model to preserve the exact visual style of the frames. Name the medium and rendering (for example flat 2D cartoon or soft 3D illustration), shapes, shading, level of detail, colours, background and proportions. Then describe every person exactly as drawn in the frames, so they cannot be redrawn differently: build, hair, headwear with its shape and colour, each piece of clothing with its colour, shoes, and what they carry. Then the key objects and setting that must stay consistent. End with a sentence that forbids making the result more realistic, more detailed or different in style than the frames.

            Action: the act from the start frame to the end frame in two to four sentences of continuous present-tense narration: what the start frame shows, the motion that gets from it to the end frame, and the end frame as the result, and how the people feel and react along the way: their facial expressions, where they look and small natural gestures that fit what happens. Describe only what the two frames show and the motion between them. Do not add events, actions or objects that are not in the frames.

            Details: two to four sentences about continuity that is easy to get wrong in this shot: which objects must stay the same and where they are, where the character looks and when, how a key object moves between the frames, and the exact end pose.

            Summary: for the director, not the video model: what the video shows from the start frame to the end frame, in one or two short plain sentences, such as "The visitor bends towards the backpack, pulls her hand back without touching it and walks out of the frame."

            Rules:
            - Base everything on the frame descriptions and the style you are given. Never invent a story beat.
            - Write every action on a thing together with its outcome, so the video model cannot finish it the usual way. Video models follow actions and ignore prohibitions: "checks the door by holding the handle" ends with the door opening, however often you add "it does not open". Write "presses the handle down once, the door is locked and does not move, he lets go" instead. The same for a lid that stays shut, a gate that stays closed, a valve that does not turn.
            - Never list what must not happen to a thing, such as "it is never touched, lifted, nudged or rotated": naming those actions makes the video model do them. Describe a thing that must not move as a fixed part of the place, in plain positive words, such as "the black backpack stands upright on the floor in the same spot the whole time", also when someone reaches towards it.
            - {$tempo}
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
            'summary' => $schema->string()->required(),
        ];
    }

    /**
     * @param  iterable<Keyframe>  $keyframes
     */
    public function promptFor(iterable $keyframes): string
    {
        $shot = $this->shot;
        $style = $shot->project->style;

        $frames = collect($keyframes)->values()
            ->map(fn(Keyframe $keyframe, int $index) => ($index === 0 ? 'Start frame' : 'End frame') . ". {$keyframe->title}: {$keyframe->fullDescription()}");

        return implode("\n\n", [
            'Write the video prompt for this shot.',
            "Visual style of the project: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.",
            ($frames->count() === 1 ? 'The one frame the shot holds' : 'The frames') . ":\n" . $frames->join("\n"),
            'Where a description and its image differ, the image is right: the director may have changed it by hand.',
        ]);
    }
}
