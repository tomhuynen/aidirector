<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Project;
use App\Support\Video\OpenRouterVideoClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Animates the project cover into a short seamless loop for the header: the
 * cover is the first and the last frame, and only small things move. Runs
 * after the cover is drawn; nothing waits for it, the header shows the loop
 * once it is there.
 */
#[DeleteWhenMissingModels]
class GenerateCoverLoop implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** The widest ratio the video models offer; the cover is padded to it. */
    public const ASPECT_RATIO = '21:9';

    public const SECONDS = 5;

    public function __construct(
        public readonly Project $project,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(OpenRouterVideoClient $videos): void
    {
        $cover = $this->project->getFirstMedia(Project::COVER);

        if ($cover === null) {
            return;
        }

        $frame = 'data:image/jpeg;base64,' . base64_encode(self::paddedFrame((string) file_get_contents($cover->getPath())));
        $model = (string) Config::get('pipeline.models.cover_loop');

        $jobId = $videos->submit(
            $model,
            self::prompt(),
            [],
            self::SECONDS,
            self::ASPECT_RATIO,
            '720p',
            ['first_frame' => $frame, 'last_frame' => $frame],
        );

        PollCoverLoop::dispatch($this->project, $jobId, $cover->id, now()->getTimestamp())
            ->delay(now()->addSeconds((int) Config::get('pipeline.video.poll_seconds')));
    }

    public function failed(?Throwable $exception): void
    {
        $this->project->generations()->create([
            'director_id' => $this->project->director_id,
            'kind' => 'video',
            'provider' => 'openrouter',
            'model' => Config::get('pipeline.models.cover_loop'),
            'prompt' => self::prompt(),
            'error' => $exception?->getMessage(),
        ]);
    }

    public static function prompt(): string
    {
        return implode("\n\n", [
            'Bring this still illustration gently to life as a seamless loop. The first and the last frame are exactly the supplied image, so the clip loops without a visible jump.',
            'Animation in place only. Nobody walks, steps, turns around or changes position: feet stay planted on the same spot for the whole clip and every person keeps the same place in the frame. This includes people in the background: someone drawn mid-step stays frozen in that step and does not walk on. Nobody enters or leaves the frame.',
            'The people talk to each other: neighbours turn their heads towards one another, take turns speaking with small mouth movements, nod, smile and make a small hand gesture now and then, and listen. Around them only small things move: hair and loose clothing stirring in a light breeze, water rippling softly, ropes swaying. Everyone is back in their exact starting pose by the last frame.',
            'Calm and unhurried, never busy. Keep the camera completely still: no zoom, pan or cuts. Keep the drawing style, colours and composition exactly as they are. The band in the middle is what is seen; keep the areas at the top and bottom calm.',
            'No text, no new people or objects, no sound.',
        ]);
    }

    /**
     * The cover on a 21:9 canvas: a blurred, stretched copy fills the bands
     * above and below, which the header crops away again.
     */
    public static function paddedFrame(string $image): string
    {
        $cover = imagecreatefromstring($image);

        if ($cover === false) {
            return $image;
        }

        $width = imagesx($cover);
        $height = (int) round($width * 9 / 21);
        $canvas = imagecreatetruecolor($width, $height);

        $blur = imagecreatetruecolor(max(1, intdiv($width, 16)), max(1, intdiv($height, 16)));
        imagecopyresampled($blur, $cover, 0, 0, 0, 0, imagesx($blur), imagesy($blur), $width, imagesy($cover));

        for ($pass = 0; $pass < 4; $pass++) {
            imagefilter($blur, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagecopyresampled($canvas, $blur, 0, 0, 0, 0, $width, $height, imagesx($blur), imagesy($blur));
        imagecopy($canvas, $cover, 0, intdiv($height - imagesy($cover), 2), 0, 0, $width, imagesy($cover));

        ob_start();
        imagejpeg($canvas, null, 90);

        return (string) ob_get_clean();
    }
}
