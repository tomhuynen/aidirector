<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Briefs\PurposeBrief;
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
class StorylineWriter implements Agent, HasStructuredOutput
{
    use Promptable;

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

            Rules:
            - Produce between {$min} and {$max} keyframes. Use the fewest that tell the story clearly.
            - Title: two to four words naming the moment.
            - Description: one or two sentences describing exactly what is visible, in present tense. Name the subject, the pose, the key object and its state.
            - Keep the same subject, environment and objects across all keyframes. Do not introduce new characters or props that the storyline does not imply.
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
