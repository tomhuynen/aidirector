<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stringable;

/**
 * Proposes the styles for one round of the style exploration. The first
 * round spreads across the whole spectrum from photoreal to flat cartoon;
 * later rounds stay close to the style the director picked.
 */
class StyleOptionsWriter implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    public function __construct(
        private readonly Project $project,
        private readonly ?StyleOption $parent = null,
    ) {}

    public function instructions(): Stringable|string
    {
        $count = $this->count();
        $purpose = $this->project->purpose->description();
        $subjects = $this->subjects();

        return <<<INSTRUCTIONS
            You are an experienced art director choosing a visual style for an animated production. The director will pick from rendered style sheets, so every style you propose must be visually distinct and easy to name.

            Project: {$this->project->title}
            Project description: {$this->project->description}
            Purpose: {$purpose}
            Subjects that appear in the sheets: {$subjects}

            Propose exactly {$count} styles. For each give:
            - name: two to four words the director will see under the tile, such as "Soft 3D cartoon" or "Documentary photoreal".
            - look: one sentence on shapes, level of detail and finish.
            - medium: the technique, such as "3D render", "flat vector", "gouache painting", "photograph".
            - mood: a few words.
            - palette: the dominant colours and how saturated they are.
            - lighting: how the scene is lit.
            Write in English, concrete and short. No camera language, no text in the image.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $count = $this->count();

        return [
            'styles' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'look' => $schema->string()->required(),
                    'medium' => $schema->string()->required(),
                    'mood' => $schema->string()->required(),
                    'palette' => $schema->string()->required(),
                    'lighting' => $schema->string()->required(),
                ]))
                ->min($count)
                ->max($count)
                ->required(),
        ];
    }

    public function promptText(): string
    {
        $count = $this->count();

        if ($this->parent === null) {
            return <<<PROMPT
                Propose {$count} styles that span the whole range from photoreal to flat cartoon, so the director can see which end of the spectrum feels right. Make them as different from each other as possible while each still fits the project's purpose.
                PROMPT;
        }

        $style = $this->parent->style();

        return <<<PROMPT
            The director liked this style and wants more like it:
            Name: {$style['name']}
            Look: {$style['look']}
            Medium: {$style['medium']}
            Mood: {$style['mood']}
            Palette: {$style['palette']}
            Lighting: {$style['lighting']}

            Propose {$count} variations that stay in this family. Keep the medium close; vary the look, palette, mood and lighting one or two notches at a time so the differences are visible but nothing jumps to another style altogether. Do not repeat the original.
            PROMPT;
    }

    private function count(): int
    {
        return (int) Config::get('pipeline.style_options_count');
    }

    private function subjects(): string
    {
        $captions = $this->project->getMedia(Project::CONTENT_REFERENCES)
            ->map(fn(Media $media) => $media->getCustomProperty(Project::CAPTION))
            ->filter()
            ->values();

        return $captions->isEmpty() ? 'the project subjects described above' : $captions->join('; ');
    }
}
