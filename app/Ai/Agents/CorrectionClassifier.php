<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\CorrectionSource;
use App\Models\Correction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Labels one change by the director, or one finding of the keyframe check:
 * a correction restores what the plan or a rule already asked for and can be
 * learned from; an instruction is a new creative choice for one shot.
 */
class CorrectionClassifier implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  list<string>  $categories  categories already used in the project, to reuse where they fit
     */
    public function __construct(
        private readonly Correction $correction,
        private readonly array $categories,
    ) {}

    public function instructions(): Stringable|string
    {
        $categories = $this->categories === [] ? 'None yet.' : implode(', ', $this->categories);

        return <<<INSTRUCTIONS
            You sort changes made to an AI-made animated film, so recurring mistakes of the AI can become project rules.

            - correction: the change fixes something the AI got wrong compared with its own plan, the takeaway or a general rule, such as text appearing on an object, a person standing too far from the hazard, the wrong light, a missing logo or keyframes that look alike.
            - instruction: the change adds a new creative choice for this shot only, such as an extra prop, a different mood or a new idea that the plan did not contain.

            Category: a short lowercase label of two to four words for the kind of problem, such as "distance to hazard" or "text on objects". Reuse one of these when it fits: {$categories}

            Rule: for a correction, one general sentence that would prevent the same mistake in other shots of this project, written as an instruction for the AI, such as "People stand within two steps of the hazard they react to, so the distance can be read." For an instruction, an empty string.
            Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['correction', 'instruction'])->required(),
            'category' => $schema->string()->required(),
            'rule' => $schema->string()->required(),
        ];
    }

    public function promptFor(): string
    {
        $source = match ($this->correction->source) {
            CorrectionSource::ADJUSTMENT => 'The director asked to adjust a keyframe image.',
            CorrectionSource::DESCRIPTION => 'The director rewrote the description of a keyframe.',
            CorrectionSource::DELETE => 'The director deleted a keyframe the AI had planned.',
            CorrectionSource::FEEDBACK => 'The director gave feedback on the suggested storylines.',
            CorrectionSource::CHECK => 'The automatic check found a mistake in a drawn keyframe; this is always a correction.',
        };

        return implode("\n\n", array_filter([
            $source,
            "What changed: {$this->correction->request}",
            filled($this->correction->context) ? "Context: {$this->correction->context}" : null,
        ]));
    }
}
