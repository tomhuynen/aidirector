<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\SetsReasoningEffort;
use App\Ai\Contracts\HasReasoningEffort;
use App\Enums\ElementType;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Finds the people, places and objects in a planned shot that are worth
 * keeping as cast and sets: the new ones, and the ones that look like an
 * existing element the plan did not name. It looks at the chosen keyframe 1,
 * so the descriptions match what was actually drawn.
 */
class ElementDetector implements Agent, HasReasoningEffort, HasStructuredOutput
{
    use Promptable;
    use SetsReasoningEffort;

    /**
     * @param  Collection<int, Keyframe>  $keyframes  with elements loaded
     */
    public function __construct(
        private readonly Shot $shot,
        private readonly Collection $keyframes,
        private readonly StoredImage $firstKeyframe,
    ) {}

    public function instructions(): Stringable|string
    {
        $types = implode(', ', array_column(ElementType::cases(), 'value'));

        return <<<INSTRUCTIONS
            You keep the cast and sets of an animated production consistent across shots. Cast and sets are recurring people, places and objects: they get a fixed description and a reference image, and later shots reuse them.

            You get the keyframe plan of one shot, the image of its keyframe 1 and the cast and sets the project already has. List the people, places and objects in this shot that are worth keeping:
            - Every person who is clearly visible.
            - The place where the shot plays, as one place.
            - Objects that matter for the story or are likely to come back, such as a vehicle, a gate or a sign. Skip small incidental props such as a cigarette, a pen or a sheet of paper.
            - Leave out anything the plan already names as an existing element for a keyframe.

            For each give:
            - name: short and recognisable, such as "Visitor in navy suit" or "Shipyard main gate".
            - type: one of {$types}.
            - description: one or two sentences about appearance only, as drawn in keyframe 1 when it appears there: for a person their age, build, hair, face and clothing; for a place its buildings, surfaces and colours; for an object its shape, colours and markings. No pose, no action, no mood.
            - keyframes: the numbers of the keyframes it appears in.
            - match: the exact name of an existing element when this is clearly the same person, place or object, otherwise an empty string.

            Return an empty list when there is nothing worth keeping. Write in English.
            INSTRUCTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'elements' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'type' => $schema->string()->enum(array_column(ElementType::cases(), 'value'))->required(),
                    'description' => $schema->string()->required(),
                    'keyframes' => $schema->array()->items($schema->integer())->required(),
                    'match' => $schema->string()->required(),
                ]))
                ->max(8)
                ->required(),
        ];
    }

    public function promptText(): string
    {
        $plan = $this->keyframes
            ->map(function (Keyframe $keyframe) {
                $named = $keyframe->elements->pluck('name')->join(', ');

                return "{$keyframe->position}. {$keyframe->title}: {$keyframe->description}" . ($named !== '' ? " Named elements: {$named}." : '');
            })
            ->join("\n");

        return implode("\n\n", [
            "Shot: {$this->shot->title}. Subject: {$this->shot->subject}.",
            "Keyframe plan:\n{$plan}",
            "Existing cast and sets:\n{$this->shot->project->elementsBrief()}",
            'The attached image is keyframe 1 of this shot.',
        ]);
    }

    /**
     * @return list<StoredImage>
     */
    public function attachments(): array
    {
        return [$this->firstKeyframe];
    }
}
