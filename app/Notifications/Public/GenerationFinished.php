<?php

declare(strict_types=1);

namespace App\Notifications\Public;

use App\Models\Project;
use Illuminate\Notifications\Notification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Tells the director that a long generation finished, or failed, with a
 * link to the page where the result is shown. Stored in the database; the
 * app picks new ones up while open and shows them as toasts and in the bell.
 *
 * The image is kept by media id and signed when the notification is read,
 * because signed media links expire.
 */
class GenerationFinished extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly bool $failed = false,
        public readonly ?int $imageId = null,
        public readonly ?string $imageConversion = null,
    ) {}

    /**
     * A result is ready to look at, optionally with a picture of it.
     */
    public static function ready(string $title, string $url, ?Media $image = null, ?string $conversion = null): self
    {
        $id = $image?->getKey();

        return new self($title, $url, imageId: is_int($id) ? $id : null, imageConversion: $image === null ? null : $conversion);
    }

    /**
     * A generation failed; the link leads to where it can be tried again.
     */
    public static function failed(string $title, string $url): self
    {
        return new self($title, $url, failed: true);
    }

    /**
     * Sends it to the project's director. A notification that cannot be
     * stored is reported but never fails the generation it is about.
     */
    public function sendTo(Project $project): void
    {
        try {
            $project->director?->notify($this);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, url: string, failed: bool, image: array{id: int, conversion: string|null}|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'failed' => $this->failed,
            'image' => $this->imageId === null ? null : ['id' => $this->imageId, 'conversion' => $this->imageConversion],
        ];
    }
}
