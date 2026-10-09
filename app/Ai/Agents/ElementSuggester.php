<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Briefs\TextRules;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Element;
use App\Models\ElementRound;
use App\Support\Elements\PhotoInventory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the suggestions for one cast and sets round: distinct people,
 * places or objects that fit the director's confirmed brief, the project
 * and the uploaded photos, each described so it can be drawn.
 */
class ElementSuggester implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly ElementRound $round,
    ) {}

    public function instructions(): Stringable|string
    {
        $project = $this->round->project;
        $type = $this->round->type;
        $count = $this->count();
        $photos = PhotoInventory::describe($project);
        $existing = $project->elements()->get()->map(fn(Element $element) => "- {$element->promptLine()}")->join("\n") ?: 'none yet';
        $documents = $project->sharedDocuments();
        $documents = $documents === '' ? '' : "\nDocuments the director shared. They may name and describe the {$type->plural()} of this project:\n{$documents}\n";

        return <<<INSTRUCTIONS
            You suggest recurring {$type->plural()} for an animated production, so the director can pick which ones to keep as cast and sets. Each suggestion will be drawn on its own as a reference image.

            Project: {$project->title}
            Description: {$project->description}
            Purpose: {$project->purpose->description()}
            {$documents}
            Uploaded photos:
            {$photos}

            Already in the cast and sets:
            {$existing}

            Write exactly {$count} suggestions. For each give:
            - "name": two to four words the director will see under the image, such as "Security guard" or "Visitor in hi-vis".
            - "description": one or two sentences on how it looks, concrete enough to draw it the same way every time: build, age range, clothing and colours for people; layout, materials and landmarks for places; shape, size, colours and markings for objects. Text: {$this->textRule()}
            - "photo": the number of the uploaded photo it is taken from, when it is something visible in that photo; otherwise null.
            Rules:
            - Follow the director's brief closely. Cover its variety first, then add useful alternatives.
            - Within the brief, take what the shared documents name before your own ideas, and describe it as they do.
            - Prefer things from the photos where they fit the brief, and describe them as they look there.
            - Every suggestion must be clearly different from the others and from what is already in the cast and sets.
            - No names of real people. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $count = $this->count();

        return [
            'suggestions' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'photo' => $schema->integer()->nullable()->required(),
                ]))
                ->min($count)
                ->max($count)
                ->required(),
        ];
    }

    public function promptText(): string
    {
        return "The director's brief for the {$this->round->type->plural()}: {$this->round->brief}";
    }

    public function count(): int
    {
        return (int) Config::get('pipeline.element_suggestions_count');
    }

    /**
     * No text on anything, except the logos of the project's branding.
     */
    private function textRule(): string
    {
        return TextRules::forPlanning($this->round->project);
    }
}
