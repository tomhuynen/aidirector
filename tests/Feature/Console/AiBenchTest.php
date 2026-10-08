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
            ['keyframe-plan', 'Plan a shot from its takeaway: storyline and keyframe descriptions.', 0],
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
        ->expectsOutputToContain('model not on OpenRouter')
        ->assertSuccessful();
});

it('plans every shot from its takeaway', function () {
    Shot::factory()->for($this->project)->create(['takeaway' => 'Stop at the gate']);
    Shot::factory()->for($this->project)->create(['takeaway' => 'Keep to the quay path']);

    StorylineWriter::fake(fn() => ['keyframes' => []]);

    $this->artisan('ai:bench', ['benchmarks' => ['keyframe-plan'], '--no-judge' => true])
        ->expectsOutputToContain('2 cases × 1 variants × 1 runs = 2 calls.')
        ->assertSuccessful();

    StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Takeaway: Stop at the gate'));
    StorylineWriter::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Takeaway: Keep to the quay path'));
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
