<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Client\Events\ResponseReceived;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;

/**
 * Keeps what an image model said in its last reply. The AI SDK drops the
 * text when a model answers without an image, which is how refusals and
 * mix-ups arrive; with this the failure explains itself in the log.
 */
class ImageReplies
{
    private ?string $text = null;

    private ?string $finishReason = null;

    /**
     * Listens to every HTTP response and keeps the ones from an image request.
     */
    public function record(ResponseReceived $event): void
    {
        $body = $event->request->data();

        if (! str_ends_with($event->request->url(), 'chat/completions') || ! in_array('image', (array) ($body['modalities'] ?? []), true)) {
            return;
        }

        $message = $event->response->json('choices.0.message') ?? [];
        $content = $message['content'] ?? null;

        $this->text = is_array($content)
            ? trim(collect($content)->pluck('text')->filter()->join(' '))
            : (is_string($content) ? trim($content) : null);

        $this->finishReason = $event->response->json('choices.0.native_finish_reason') ?? $event->response->json('choices.0.finish_reason');
    }

    /**
     * The first image of the response, or an error carrying the model's own reply.
     */
    public function firstImage(ImageResponse $response): GeneratedImage
    {
        if ($response->images->isNotEmpty()) {
            return $response->firstImage();
        }

        $reason = $this->finishReason ? " Finish reason: {$this->finishReason}." : '';
        $reply = filled($this->text) ? " The model replied: \"{$this->text}\"" : ' The model gave no text either.';

        throw new RuntimeException("The image model returned no image.{$reason}{$reply}");
    }
}
