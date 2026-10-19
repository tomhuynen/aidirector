<?php

declare(strict_types=1);

use App\Ai\Agents\BenchJudge;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Agents\StyleOptionsWriter;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Config::set('concurrency.default', 'sync');
    Config::set('pipeline.bench.judges', ['anthropic/claude-opus-5.5', 'openai/gpt-5.6-sol']);
    Storage::fake('local');

    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
        ['id' => 'openai/gpt-5.5', 'pricing' => ['prompt' => '0.000005', 'completion' => '0.00003']],
        ['id' => 'google/gemini-3.8-flash', 'pricing' => ['prompt' => '0.00000075', 'completion' => '0.00000375']],
        ['id' => 'anthropic/claude-opus-5.5', 'pricing' => ['prompt' => '0.000004', 'completion' => '0.00002']],
        ['id' => 'openai/gpt-5.6-sol', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
    ]])]);

    $this->project = Project::factory()->create();
});

function benchStyles(): array
{
    return collect(range(1, 4))->map(fn(int $number) => [
        'name' => "Style {$number}",
        'look' => 'Chunky shapes and flat colour.',
        'medium' => 'flat vector',
        'mood' => 'friendly',
        'palette' => 'blue and orange',
        'lighting' => 'even daylight',
    ])->all();
}

function benchScores(): array
{
    return ['distinct' => 4, 'fit' => 5, 'concrete' => 3, 'rules' => 4, 'note' => 'Styles 2 and 3 are close.'];
}

/**
 * The report the last run saved.
 */
function benchReport(): array
{
    $files = Storage::disk('local')->files('bench');

    expect($files)->toHaveCount(1);

    return json_decode(Storage::disk('local')->get($files[0]), true);
}

it('lists the benchmarks with the cases this tenant has for them', function () {
    $this->artisan('ai:bench')
        ->expectsTable(['Benchmark', 'What it runs', 'Cases'], [
            ['photo-analysis', 'Caption an uploaded photo and list the people, places and objects in it.', 0],
            ['style-options', 'Propose a round of styles, the first one or "more like this" from a style sheet.', 1],
            ['cast-suggestions', 'Suggest recurring people, places or objects from the brief confirmed in the chat.', 0],
            ['scene-direction', 'Direct a story into shots: the decisive moment, the fewest shots, intent made visible.', 0],
            ['keyframe-plan', 'Plan a shot from its idea: storyline and keyframe descriptions.', 0],
            ['keyframe-check', 'Check a drawn keyframe: unnatural poses and what a viewer would see change.', 0],
            ['keyframe-edit', 'Edit a drawn keyframe: make one change and keep the rest of the image identical.', 0],
        ])
        ->assertSuccessful();
});

it('runs every model on every case and saves the answers, scores and costs', function () {
    StyleOptionsWriter::fake(fn() => ['styles' => benchStyles()]);
    BenchJudge::fake(fn() => benchScores());

    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--model' => ['openai/gpt-5.5', 'google/gemini-3.8-flash'], '--runs' => 2])
        ->expectsOutputToContain('1 cases × 2 variants × 2 runs = 4 calls, each scored by 2 judges.')
        ->assertSuccessful();

    $report = benchReport();

    expect($report['benchmark'])->toBe('style-options')
        ->and($report['results'])->toHaveCount(4)
        ->and($report['results'][0]['output']['styles'])->toBe(benchStyles())
        ->and(array_keys($report['results'][0]['judgements']))->toBe(['anthropic/claude-opus-5.5', 'openai/gpt-5.6-sol'])
        ->and($report['summary'])->toHaveCount(2)
        ->and($report['summary'][0])->toMatchArray([
            'variant' => 'openai/gpt-5.5 · configured',
            'ok' => '2/2',
            'quality' => 4.0,
            'scores' => ['distinct' => 4, 'fit' => 5, 'concrete' => 3, 'rules' => 4],
        ]);

    StyleOptionsWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'span the whole range from photoreal to flat cartoon'));
    BenchJudge::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'ANSWER TO REVIEW:') && str_contains($prompt->prompt, 'Style 4'));
});

it('averages the scores of every judge over every answer', function () {
    StyleOptionsWriter::fake(fn() => ['styles' => benchStyles()]);
    $scores = [2, 4, 3, 5];
    BenchJudge::fake(function () use (&$scores) {
        return [...benchScores(), 'fit' => array_shift($scores)];
    });

    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--runs' => 2, '--concurrency' => 1])->assertSuccessful();

    expect(benchReport()['summary'][0]['scores']['fit'])->toBe(3.5);
});

it('sends the model and reasoning effort of every variant to OpenRouter', function () {
    Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
        'model' => 'openai/gpt-5.5',
        'choices' => [['message' => ['content' => json_encode(['styles' => benchStyles()])], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 400],
    ])]);

    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--effort' => ['default', 'low'], '--no-judge' => true])
        ->assertSuccessful();

    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'chat/completions') && $request['model'] === 'openai/gpt-5.5' && ! isset($request['reasoning']));
    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'chat/completions') && ($request->data()['reasoning'] ?? null) === ['effort' => 'low']);

    $report = benchReport();

    // 1,000 prompt tokens at $5 and 400 answer tokens at $30 per million.
    expect($report['results'][0]['cost'])->toEqualWithDelta(0.017, 0.000001)
        ->and($report['results'][0]['judgements'])->toBe([])
        ->and(collect($report['summary'])->pluck('variant')->all())->toBe(['openai/gpt-5.5 · default', 'openai/gpt-5.5 · low']);
});

it('records a failed call and carries on with the rest', function () {
    StyleOptionsWriter::fake(fn() => throw new RuntimeException('Provider unavailable'));

    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--no-judge' => true])
        ->expectsOutputToContain('Provider unavailable')
        ->assertSuccessful();

    $report = benchReport();

    expect($report['results'][0]['error'])->toBe('Provider unavailable')
        ->and($report['summary'][0])->toMatchArray(['ok' => '0/1', 'median' => null, 'quality' => null]);
});

it('estimates the cost in a dry run without calling any model', function () {
    StyleOptionsWriter::fake();
    BenchJudge::fake();

    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--model' => ['openai/gpt-5.5', 'google/gemini-3.8-flash'], '--dry-run' => true])
        ->expectsOutputToContain('Estimated total')
        ->expectsOutputToContain('Nothing was sent to a model')
        ->assertSuccessful();

    StyleOptionsWriter::assertNeverPrompted();
    BenchJudge::assertNeverPrompted();

    expect(Storage::disk('local')->files('bench'))->toBe([]);
});

it('flags a model OpenRouter does not list in a dry run', function () {
    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--model' => ['acme/unknown-model'], '--no-judge' => true, '--dry-run' => true])
        ->expectsOutputToContain('no price on OpenRouter')
        ->assertSuccessful();
});

it('plans every shot from its idea', function () {
    Shot::factory()->for($this->project)->create(['idea' => 'A driver stops at the gate.']);
    Shot::factory()->for($this->project)->create(['idea' => 'A visitor keeps to the quay path.']);

    StorylineWriter::fake(fn() => ['keyframes' => []]);

    $this->artisan('ai:bench', ['benchmarks' => ['keyframe-plan'], '--no-judge' => true])
        ->expectsOutputToContain('2 cases × 1 variants × 1 runs = 2 calls.')
        ->assertSuccessful();

    StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Idea: A driver stops at the gate.'));
    StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Idea: A visitor keeps to the quay path.'));
});

it('checks every drawn keyframe with the images and model the app uses, judged on natural poses', function () {
    Storage::fake(App\Enums\Disk::TENANT->value);
    $shot = Shot::factory()->for($this->project)->create(['status' => App\Enums\ShotStatus::KEYFRAMES_READY]);

    foreach ([1, 2, 3] as $position) {
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $keyframe = App\Models\Keyframe::factory()->for($shot)->create(['position' => $position]);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(App\Models\Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
    }

    App\Ai\Agents\KeyframeChecker::fake(fn() => ['inventory' => [], 'issues' => []]);

    $this->artisan('ai:bench', ['benchmarks' => ['keyframe-check'], '--no-judge' => true])
        ->expectsOutputToContain('2 cases × 1 variants × 1 runs = 2 calls.')
        ->assertSuccessful();

    App\Ai\Agents\KeyframeChecker::assertPrompted(fn($prompt) => $prompt->model === Config::get('pipeline.models.keyframe_check')
        && $prompt->attachments->count() >= 2
        && str_contains((string) $prompt->agent->instructions(), App\Ai\Briefs\BodyRules::NATURAL_POSE));
    expect(app(App\Ai\Bench\Benchmarks::class)->find('keyframe-check')->criteria())->toHaveKey('natural_pose');
});

it('edits keyframes with a person, one kind of edit each, and measures how much of the image stayed', function () {
    Storage::fake(App\Enums\Disk::TENANT->value);
    $shot = Shot::factory()->for($this->project)->create(['status' => App\Enums\ShotStatus::KEYFRAMES_READY]);
    $visitor = App\Models\Element::factory()->for($this->project)->create(['name' => 'Helmeted visitor']);
    $striped = function (): string {
        $image = imagecreatetruecolor(72, 128);
        foreach (range(0, 127, 8) as $y) {
            imagefilledrectangle($image, 0, $y, 71, $y + 3, imagecolorallocate($image, 250, 250, 250));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };

    foreach ([1, 2, 3] as $position) {
        $keyframe = App\Models\Keyframe::factory()->for($shot)->create(['position' => $position]);
        $render = $keyframe->addMediaFromString($striped())->usingFileName("k{$position}.png")->toMediaCollection(App\Models\Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();

        if ($position < 3) {
            $keyframe->elements()->attach($visitor);
        }
    }

    // The edited image is the render itself: nothing moved.
    Http::fake(['openrouter.ai/api/v1/images' => Http::response(['data' => [['b64_json' => base64_encode($striped()), 'media_type' => 'image/png']], 'usage' => ['cost' => 0.02]])]);
    BenchJudge::fake(fn() => ['edit_done' => 2, 'rest_identical' => 5, 'note' => 'Nothing changed.']);

    $this->artisan('ai:bench', ['benchmarks' => ['keyframe-edit'], '--model' => ['krea/k3-edit']])
        ->expectsOutputToContain('2 cases × 1 variants × 1 runs = 2 calls, each scored by 2 judges.')
        ->assertSuccessful();

    $report = collect(Storage::disk('local')->files('bench'))->map(fn(string $file) => json_decode(Storage::disk('local')->get($file), true))->first();

    expect(collect($report['results'])->pluck('case')->sort()->values()->all())->toBe(collect(App\Models\Keyframe::query()->orderBy('id')->take(2)->pluck('id'))->map(fn(int $id, int $index) => "keyframe-{$id}-" . ['head', 'step'][$index])->sort()->values()->all())
        ->and($report['summary'][0])->toMatchArray([
            'ok' => '2/2',
            'cost' => 0.02,
            'measures' => ['kept' => 1.0, 'moved' => 0.0],
            'scores' => ['edit_done' => 2, 'rest_identical' => 5],
        ])
        ->and(Storage::disk('local')->files('bench/images'))->toHaveCount(2);

    Http::assertSent(fn(Request $request) => str_ends_with($request->url(), 'images')
        && $request['model'] === 'krea/k3-edit'
        && str_contains($request['prompt'], 'Change only this: Helmeted visitor turns their head to look straight at the camera.')
        && count($request['input_references']) === 1);
    BenchJudge::assertPrompted(fn($prompt) => $prompt->attachments->count() === 2);
});

it('skips a benchmark this tenant has no data for', function () {
    $this->artisan('ai:bench', ['benchmarks' => ['keyframe-plan'], '--no-judge' => true])
        ->expectsOutputToContain('No cases')
        ->assertSuccessful();

    expect(Storage::disk('local')->files('bench'))->toBe([]);
});

it('rejects an unknown reasoning effort', function () {
    $this->artisan('ai:bench', ['benchmarks' => ['style-options'], '--effort' => ['extreme']])
        ->expectsOutputToContain('Unknown effort: extreme')
        ->assertFailed();
});

describe('reasoning effort', function () {
    it('sends the effort configured for the agent', function () {
        Config::set('pipeline.reasoning_effort.style_options_writer', 'medium');

        expect((new StyleOptionsWriter($this->project))->providerOptions('openrouter'))->toBe(['reasoning' => ['effort' => 'medium']]);
    });

    it('lets a call override the configured effort', function () {
        Config::set('pipeline.reasoning_effort.style_options_writer', 'medium');

        expect((new StyleOptionsWriter($this->project))->withReasoningEffort('low')->providerOptions('openrouter'))->toBe(['reasoning' => ['effort' => 'low']]);
    });

    it('leaves the effort to the model when none is configured or "default" is asked for', function () {
        Config::set('pipeline.reasoning_effort.style_options_writer', null);

        expect((new StyleOptionsWriter($this->project))->providerOptions('openrouter'))->toBe([])
            ->and((new StyleOptionsWriter($this->project))->withReasoningEffort('default')->providerOptions('openrouter'))->toBe([]);
    });
});
