<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Prompts\SequencePrompt;
use App\Enums\ElementType;
use App\Models\Element;
use App\Models\Shot;
use App\Support\Images\ReplicateClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Renders a merged sequence as one clip with the sequence video model: the
 * start frame of the first part opens it, the start frames of the other
 * parts and the pictures of the people in it are references, and the model
 * cuts between the parts and times them itself. {@see PollSequenceVideo}
 * waits for the clip.
 */
#[DeleteWhenMissingModels]
class RenderSequenceVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** The most reference images the model takes without a reference video. */
    public const int REFERENCES = 7;

    /** The most shots in one clip. */
    public const int SHOTS = 6;

    /** The shortest and the longest clip the model renders, in seconds. */
    private const int SHORTEST = 3;

    private const int LONGEST = 15;

    public function __construct(
        public readonly Shot $shot,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(ReplicateClient $replicate): void
    {
        $shot = $this->shot->load(['project', 'parts.keyframes.media', 'parts.keyframes.elements']);
        $parts = $shot->parts->values();
        $frames = $parts->map(fn(Shot $part) => $part->keyframes->sortBy('position')->first()?->render());

        if ($parts->count() < 2 || $parts->count() > self::SHOTS || $frames->contains(null)) {
            throw new RuntimeException('Every part of the sequence needs its start frame.');
        }

        $images = [];
        $references = [];

        foreach ($frames->skip(1) as $index => $frame) {
            $references[$index] = count($images) + 1;
            $images[] = $this->upload($replicate, $frame);
        }

        $cast = [];

        foreach ($this->people($parts) as $person) {
            if (count($images) >= self::REFERENCES) {
                break;
            }

            $cast[$person->name] = count($images) + 1;
            $images[] = $this->upload($replicate, $person->reference());
        }

        $prompt = SequencePrompt::compose($parts, $references, $cast);
        $model = (string) Config::get('pipeline.models.sequence_video');

        $jobId = $replicate->start($model, [
            'prompt' => $prompt,
            'start_image' => $this->upload($replicate, $frames->first()),
            'reference_images' => $images,
            'duration' => max(self::SHORTEST, min(self::LONGEST, $parts->sum(fn(Shot $part) => $part->durationInSeconds()))),
            'aspect_ratio' => $shot->aspectRatio()->value,
            'mode' => $shot->videoResolution() === '1080p' ? 'pro' : 'standard',
            'generate_audio' => false,
            'negative_prompt' => 'talking, mouth moving, looking at the camera, text, captions, subtitles, music, extra people',
        ]);

        $shot->forceFill([
            'video_prompt' => $prompt,
            'video_job_id' => $jobId,
            'video_submitted_at' => now(),
            'video_error' => null,
        ])->save();

        PollSequenceVideo::dispatch($shot, $jobId)->delay((int) Config::get('pipeline.video.first_poll_seconds'));
    }

    public function failed(?Throwable $exception): void
    {
        PollSequenceVideo::markFailed($this->shot, (string) $exception?->getMessage());
    }

    /**
     * The people of the sequence with a picture, in the order they first appear.
     *
     * @param  Collection<int, Shot>  $parts
     * @return Collection<int, Element>
     */
    private function people(Collection $parts): Collection
    {
        return $parts->flatMap(fn(Shot $part) => $part->keyframes->flatMap(fn($keyframe) => $keyframe->elements))
            ->filter(fn(Element $element) => $element->type === ElementType::PERSON)
            ->unique('id')
            ->each(fn(Element $element) => $element->loadMissing('media'))
            ->filter(fn(Element $element) => $element->reference() !== null)
            ->values();
    }

    private function upload(ReplicateClient $replicate, Media $media): string
    {
        $stream = $media->stream();

        return $replicate->upload(is_resource($stream) ? (string) stream_get_contents($stream) : '', $media->file_name, (string) $media->mime_type);
    }
}
