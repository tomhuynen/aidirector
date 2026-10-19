<?php

declare(strict_types=1);

namespace App\Ai\Bench\Benchmarks;

use App\Ai\Bench\BenchAnswer;
use App\Ai\Bench\BenchCase;
use App\Ai\Bench\Benchmark;
use App\Ai\Bench\BenchVariant;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Keyframe;
use App\Support\Images\BackgroundDrift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use RuntimeException;

/**
 * Editing a drawn keyframe with the prompt the app sends for a tweak: one
 * change is made and the rest of the image stays identical. How much of the
 * image stayed in place is measured in code; the judges score whether the
 * change was made and whether anything else changed.
 */
class KeyframeEditBenchmark extends Benchmark
{
    /**
     * The edits tried, one per keyframe in turn: a pose, a move, an added
     * object and a colour. `{name}` is a person in the keyframe.
     */
    private const array EDITS = [
        'head' => '{name} turns their head to look straight at the camera.',
        'step' => '{name} stands one step further to the left.',
        'object' => 'A small red toolbox stands on the floor beside the feet of {name}.',
        'colour' => 'The top {name} wears is plain dark green.',
    ];

    public function __construct(
        private readonly KeyframePainter $painter,
        private readonly BackgroundDrift $drift,
    ) {}

    public function name(): string
    {
        return 'keyframe-edit';
    }

    public function description(): string
    {
        return 'Edit a drawn keyframe: make one change and keep the rest of the image identical.';
    }

    public function cases(): Collection
    {
        $edits = array_keys(self::EDITS);

        return Keyframe::query()->with(['shot.project', 'elements'])->whereNotNull('render_id')->orderBy('id')->get()
            ->filter(fn(Keyframe $keyframe) => $this->person($keyframe) !== null)
            ->values()
            ->map(function (Keyframe $keyframe, int $index) use ($edits) {
                $edit = $edits[$index % count($edits)];

                return new BenchCase(
                    key: "keyframe-{$keyframe->getKey()}-{$edit}",
                    label: "{$keyframe->shot->project->title} · {$keyframe->shot->code()} keyframe {$keyframe->position} · {$edit}",
                    data: ['keyframe' => $keyframe->getKey(), 'edit' => $edit],
                );
            });
    }

    public function answer(BenchCase $case, BenchVariant $variant): BenchAnswer
    {
        $keyframe = Keyframe::query()->with(['shot.project', 'elements', 'media'])->findOrFail($case->data['keyframe']);
        $render = $keyframe->render() ?? throw new RuntimeException('The keyframe has no render to edit.');
        $instruction = str_replace('{name}', (string) $this->person($keyframe)?->name, self::EDITS[$case->data['edit']]);
        $prompt = KeyframeImageBrief::tweak($keyframe->shot->project, $instruction);
        $before = $this->painter->referenceFor($render);

        $edited = $this->painter->draw($variant->model, $prompt, [$before], $keyframe->shot->aspectRatio()->value);
        $measured = $this->drift->measureImages((string) Storage::disk($render->disk)->get($render->getPathRelativeToRoot()), $edited['content']);

        $extension = $edited['mime'] === 'image/jpeg' ? 'jpg' : ($edited['mime'] === 'image/webp' ? 'webp' : 'png');
        $path = 'bench/images/' . Str::slug("{$case->key} {$variant->model}") . '-' . Str::lower(Str::random(6)) . ".{$extension}";
        Storage::disk('local')->put($path, $edited['content']);

        return new BenchAnswer(
            instructions: 'An image model edits the first attached image as the prompt asks. The second attached image is what it made.',
            prompt: $prompt,
            output: ['edited_image' => Storage::disk('local')->path($path)],
            usage: $edited['usage'],
            images: [$before, Image::fromStorage($path, 'local')],
            measures: [
                'kept' => round($measured['stillness'], 2),
                'moved' => $measured['moved'] ? 1.0 : 0.0,
            ],
        );
    }

    public function model(): string
    {
        return (string) Config::get('pipeline.models.keyframe_edit');
    }

    public function criteria(): array
    {
        return [
            'edit_done' => 'The second image makes the change the prompt asks for, fully and clearly, and it looks natural.',
            'rest_identical' => 'Everything the change does not touch is identical in the second image to the first: the people, their faces and clothes, the objects, the place, the framing, the light, the colours and the style.',
        ];
    }

    private function person(Keyframe $keyframe): ?Element
    {
        return $keyframe->elements->first(fn(Element $element) => $element->type === ElementType::PERSON);
    }
}
