<?php

declare(strict_types=1);

use App\Ai\KeyframePainter;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Facades\Http;

function imageReply(array $message, string $finishReason = 'stop'): array
{
    return ['model' => 'google/gemini-3.1-flash-image-preview', 'choices' => [['message' => $message, 'finish_reason' => $finishReason]], 'usage' => []];
}

it('records what the image model said when it returns no image', function () {
    Http::fake(['openrouter.ai/*' => Http::response(imageReply(['role' => 'assistant', 'content' => 'I can\'t create realistic images of real people.', 'images' => []], 'content_filter'))]);

    $keyframe = Keyframe::factory()->for(Shot::factory())->create();

    expect(fn() => app(KeyframePainter::class)->paint($keyframe->load('shot.project'), 'Draw the visitor.'))
        ->toThrow(RuntimeException::class, 'The image model returned no image. Finish reason: content_filter. The model replied: "I can\'t create realistic images of real people."');

    expect($keyframe->generations()->first())
        ->error->toContain('I can\'t create realistic images of real people.')
        ->prompt->toBe('Draw the visitor.');
});

it('says so when the model gave no text either', function () {
    Http::fake(['openrouter.ai/*' => Http::response(imageReply(['role' => 'assistant', 'content' => null]))]);

    $keyframe = Keyframe::factory()->for(Shot::factory())->create();

    expect(fn() => app(KeyframePainter::class)->paint($keyframe->load('shot.project'), 'Draw the visitor.'))
        ->toThrow(RuntimeException::class, 'The image model returned no image. Finish reason: stop. The model gave no text either.');
});

it('ignores responses that are not image requests', function () {
    Http::fake([
        'example.com/*' => Http::response(imageReply(['content' => 'Some chat answer.'])),
        'openrouter.ai/*' => Http::response(imageReply(['content' => null])),
    ]);

    Http::post('https://example.com/v1/chat/completions', ['messages' => []]);

    $keyframe = Keyframe::factory()->for(Shot::factory())->create();

    expect(fn() => app(KeyframePainter::class)->paint($keyframe->load('shot.project'), 'Draw.'))
        ->toThrow(RuntimeException::class, 'The model gave no text either.');
});
