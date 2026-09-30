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
 * Turns a shot brief into a handful of different interpretations of the same
 * idea, written as short prose, for the director to choose from before any
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
            Your job is to propose {$count} storylines for the shot. Each storyline is a different interpretation of the same idea, so the director can pick a direction before keyframes are planned.

            {$this->purposeBrief()}

            Project: {$project->title}
            Project description: {$project->description}
            Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.
            Shot length: about {$this->shot->durationInSeconds()} seconds.

            What the brief is:
            - The brief is an idea, not a script. The takeaway is fixed. The subject and action describe one way of showing it; treat them as the first interpretation the writer thought of, not the only one.
            - Each storyline is a different scene that lands the same takeaway. If all {$count} were filmed, a viewer should see {$count} different scenes, not {$count} takes of the same scene.

            How to make the storylines differ:
            - Change the situation, not the wording. Between any two storylines at least two of these must differ: the moment the shot starts in, where exactly in the setting it plays, which object or cue carries the point, who else is present and what they do, how the shot resolves.
            - Stay inside the project's world: the same kind of people, the same location, the same visual style. You may bring in objects, vehicles or a second person that would naturally be there when a scene needs them.
            - Not different enough: the same person in the same spot doing the same thing, once correctly, once after a nudge, once with the focus on the sign.
            - Different enough: one storyline at the entrance, one at the desk, one on the way in; or one following a single person, one a driver, one a group.
            - Before answering, describe each storyline to yourself in one line. If two of those lines read like takes of the same scene, replace one.

            Angles that suit this project's purpose. Use them as inspiration where they fit the idea; a different angle on the same scene does not count as a different storyline:
            {$this->storylineAngles()}

            Output:
            - Produce exactly {$count} storylines.
            - Title: two to four words naming what sets this interpretation apart, such as the place, the person or the moment. Not the name of an angle.
            - Storyline: two to four sentences, in present tense, describing what happens from beginning to end. Name the subject, the key object and how the shot resolves.
            - Every storyline must land the takeaway and fit within the shot length.
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
