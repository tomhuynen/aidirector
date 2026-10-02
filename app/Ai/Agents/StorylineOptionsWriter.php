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
 * Turns a takeaway, with the project's context and its cast and sets, into a
 * handful of different storylines for the director to choose from before any
 * keyframes are planned.
 */
class StorylineOptionsWriter implements Agent, HasReasoningEffort, HasStructuredOutput
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
        $count = $this->count();

        return <<<INSTRUCTIONS
            You are the storyteller of an animated production. From the director's takeaway and context you propose {$count} storylines for one shot. Each storyline is a different scene that lands the same takeaway, so the director can pick a direction before keyframes are planned.

            {$this->purposeBrief()}

            Project: {$project->title}
            Project description: {$project->description}
            Visual style: {$style['look']}. Medium: {$style['medium']}. Mood: {$style['mood']}. Palette: {$style['palette']}.
            Format: {$this->shot->aspectRatio()->value}. {$this->lengthNote()}

            Cast and sets of this project, recurring people, places and objects:
            {$project->elementsBrief()}

            How to use the cast and sets:
            - Tell the stories with these. Refer to each one by its name from the list, used as a noun with "the", as in "the visitor in hi-vis walks onto the quay". Never write "a man", "a woman" or "a person" for someone from the list, and never double the article.
            - When the director names people, places or objects they want in the shot, every storyline uses all of them.
            - The people always come from this list; never introduce a new person. Introduce a new place or recurring object only when a story needs one worth keeping for other shots, and describe it once in a few words. Small props such as a cigarette, a sign or a bin are plain words, never new cast.

            {$this->otherShots()}

            How to make the storylines differ:
            - Change the situation, not the wording. Between any two storylines at least two of these differ: the moment the shot starts in, the place, which object or cue carries the point, who else is present, how it resolves.
            - Vary who does what: do not give the same person the same role in every storyline.
            - Before answering, describe each storyline to yourself in one line. If two of those lines read like takes of the same scene, replace one.

            Angles that suit this project's purpose, as inspiration where they fit; a different angle on the same scene does not count as a different storyline:
            {$this->storylineAngles()}

            Output:
            - Produce exactly {$count} storylines.
            - Title: two to four words naming what sets this storyline apart, such as the place, the person or the moment. It also names the shot once chosen. Not the name of an angle.
            - Storyline: two to four sentences, in present tense, from beginning to end. Every storyline has one clear, visible action a viewer can follow in the shot length, not a still moment, and lands the takeaway.
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

        $brief = $shot->brief();

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

    /**
     * The other shots of the project by their takeaway, so a new shot does not
     * repeat their scene.
     */
    private function otherShots(): string
    {
        $others = $this->shot->project->shots()
            ->when($this->shot->exists, fn($shots) => $shots->whereKeyNot($this->shot->getKey()))
            ->get(['id', 'title', 'takeaway']);

        if ($others->isEmpty()) {
            return 'This is the first shot of the project.';
        }

        $lines = $others->map(fn(Shot $other) => "- {$other->title}: {$other->takeaway}")->join("\n");

        return "Other shots in this project, for continuity. Do not repeat their scene:\n{$lines}";
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

    private function lengthNote(): string
    {
        if ($this->shot->duration !== null) {
            return "The shot is about {$this->shot->duration} seconds.";
        }

        return 'The shot is short: between ' . Config::get('pipeline.video.min_duration') . ' and ' . Config::get('pipeline.video.max_duration') . ' seconds, as long as its one action needs.';
    }
}
