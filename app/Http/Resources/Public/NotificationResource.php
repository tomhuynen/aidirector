<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A finished or failed generation as the bell and the toasts show it. The
 * image is signed now, so the link is fresh whenever the list is loaded.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{title?: string, url?: string, failed?: bool, image?: array{id: int, conversion: string|null}|null} $data */
        $data = $this->data;
        $image = $data['image'] ?? null;
        $media = $image === null ? null : Media::query()->find($image['id']);
        $conversion = $image['conversion'] ?? null;

        return [
            'id' => (string) $this->id,
            'title' => (string) ($data['title'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            /** @var bool */
            'failed' => (bool) ($data['failed'] ?? false),
            /** @var string|null */
            'imageUrl' => $media?->signedUrl($conversion !== null && $media->hasGeneratedConversion($conversion) ? $conversion : null),
            /** @var bool */
            'read' => $this->read_at !== null,
            /** @var string */
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
