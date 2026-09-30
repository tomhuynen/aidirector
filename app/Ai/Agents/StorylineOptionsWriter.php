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
 * Turns a shot brief into a handful of meaningfully different storylines,
 * written as short prose, for the director to choose from before any
 * keyframes are planned.
 */
class StorylineOptionsWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly Shot $shot,
    ) {}

    public function instructions(): Stringable|string
    {
        $project = $this->shot->project;
        $style = $project->style;
        $count = $this->count();

        return <<<INSTRUCTIONS
            You are an experienced film director planning a single shot for an animated production.
            Your job is to propose {$count} meaningfully different storylines for the shot, so the director can pick a direction before keyframes are planned.

            {$this->purposeBrief()}

            Project: {$project->title}
            Project description: {$project->description}
            Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.
            Shot length: about {$this->shot->durationInSeconds()} seconds.

            Rules:
            - Produce exactly {$count} storylines. Each one must use a different angle from the list below; no two storylines may share an angle.
            - The storylines must differ in what happens and in what order. Restating the same events with a different tone or different adjectives does not count as a different storyline.
            - The brief describes one way the shot could go. Treat it as the intent, not as a fixed script: keep its subject, setting and takeaway, but let each angle change the sequence of events.
            - Title: one to three words naming the angle, such as "Correct behaviour", "Mistake and correction" or "Cue spotting".
            - Storyline: two to four sentences, in present tense, describing what happens from beginning to end. Name the subject, the key object and how the shot resolves.
            - Keep the same subject, environment and objects across all storylines. Do not introduce characters or props that the brief does not imply, unless the angle needs a witness.
            - Every storyline must land the takeaway and fit within the shot length.

            Angles for this project's purpose:
            {$this->storylineAngles()}
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
            'options' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    'storyline' => $schema->string()->required(),
                ]))
                ->min($this->count())
                ->max($this->count())
                ->required(),
        ];
    }

    /**
     * The prompt for a fresh set of storylines, or a new set that takes the
     * director's feedback on the current suggestions into account.
     */
    public function promptFor(?string $feedback = null): string
    {
        $shot = $this->shot;
        $count = $this->count();

        $brief = <<<BRIEF
            Shot title: {$shot->title}
            Subject: {$shot->subject}
            Action: {$shot->action}
            Takeaway: {$shot->takeaway}
            BRIEF;

        if (filled($shot->notes)) {
            $brief .= "\nNotes from the director: {$shot->notes}";
        }

        $current = $shot->storylineOptions();

        if (blank($feedback) || $current === []) {
            return "Propose {$count} storylines for this shot.\n\n{$brief}";
        }

        $suggestions = collect($current)
            ->map(fn(array $option, int $index) => ($index + 1) . '. ' . $option['title'] . ': ' . $option['storyline'])
            ->join("\n");

        return <<<PROMPT
            Propose {$count} new storylines for this shot. The director was not happy with the current suggestions; apply the feedback to all of them.

            {$brief}

            Current suggestions:
            {$suggestions}

            Feedback from the director: {$feedback}
            PROMPT;
    }

    private function count(): int
    {
        return (int) Config::get('pipeline.options_count');
    }

    private function purposeBrief(): string
    {
        return PurposeBrief::for($this->shot->purpose());
    }

    private function storylineAngles(): string
    {
        return PurposeBrief::storylineAngles($this->shot->purpose());
    }
}
