<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Makes a merged shot's audio track of each language from its parts' tracks,
 * each laid where its part starts in the merged video, with silence where a
 * part has none. Runs after the clips are joined and whenever a part's track
 * is spoken again.
 */
#[DeleteWhenMissingModels]
class MergeShotAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<string>  $locales
     */
    public function __construct(
        public readonly Shot $shot,
        public readonly array $locales,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    /**
     * Merge the tracks of the given languages, or of every voice-over
     * language of the project, when a part has a track to merge.
     *
     * @param  list<string>|null  $locales
     */
    public static function start(Shot $shot, ?array $locales = null): void
    {
        $shot->loadMissing(['project', 'parts.media']);
        $enabled = $shot->project->settings->enabledLocales();
        $locales = array_values(array_intersect($locales ?? $enabled, $enabled));

        $withTracks = array_values(array_filter($locales, fn(string $locale) => $shot->parts->contains(fn(Shot $part) => self::track($part, $locale) !== null)));

        if ($withTracks === [] || $shot->video() === null) {
            return;
        }

        foreach ($withTracks as $locale) {
            $shot->setVoiceOverTrack($locale, 'pending');
        }

        self::dispatch($shot, $withTracks);
    }

    public function handle(): void
    {
        $shot = $this->shot->load(['parts.media', 'media']);
        $video = $shot->video() ?? throw new RuntimeException('The merged shot has no video yet.');
        $starts = (array) $video->getCustomProperty(Shot::PART_STARTS, []);
        $seconds = (float) $video->getCustomProperty(Shot::VIDEO_SECONDS, 0);

        if (count($starts) !== $shot->parts->count() || $seconds <= 0) {
            throw new RuntimeException('Join the clips again first, so the parts can be lined up.');
        }

        foreach ($this->locales as $locale) {
            $this->mergeLocale($shot, $locale, array_map('floatval', array_values($starts)), $seconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        foreach ($this->locales as $locale) {
            $this->shot->setVoiceOverTrack($locale, 'failed', Str::limit((string) $exception?->getMessage(), 300));
        }
    }

    /**
     * @param  list<float>  $starts
     */
    private function mergeLocale(Shot $shot, string $locale, array $starts, float $seconds): void
    {
        $directory = storage_path('tmp/merge-audio-' . Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $command = [(string) Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error'];
            $filters = [];
            $labels = '';

            foreach ($shot->parts->values() as $index => $part) {
                $track = self::track($part, $locale);

                if ($track === null) {
                    continue;
                }

                $path = "{$directory}/part-{$index}.mp3";
                $stream = Storage::disk($track->disk)->readStream($track->getPathRelativeToRoot());
                File::put($path, $stream === null ? '' : (string) stream_get_contents($stream));

                $input = intdiv(count($command) - 4, 2);
                array_push($command, '-i', $path);

                $delay = (int) round($starts[$index] * 1000);
                $filters[] = "[{$input}:a]aformat=sample_rates=44100:channel_layouts=stereo,adelay={$delay}:all=1[d{$input}]";
                $labels .= "[d{$input}]";
            }

            $count = substr_count($labels, '[d');

            if ($count === 0) {
                return;
            }

            $trim = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
            $filters[] = ($count === 1 ? $labels . 'anull' : "{$labels}amix=inputs={$count}:normalize=0:duration=longest") . ",apad,atrim=0:{$trim}[a]";

            $output = "{$directory}/merged.mp3";
            array_push($command, '-filter_complex', implode(';', $filters), '-map', '[a]', '-c:a', 'libmp3lame', '-b:a', '128k', $output);

            $result = Process::timeout(240)->run($command);

            if (! $result->successful()) {
                throw new RuntimeException('ffmpeg could not merge the audio: ' . mb_substr(trim($result->errorOutput()), -500));
            }

            $shot->getMedia(Shot::VOICE_OVERS)
                ->filter(fn(Media $media) => $media->getCustomProperty('locale') === $locale)
                ->each->delete();

            $shot->addMedia($output)
                ->usingFileName("voice-over-{$shot->position}-{$locale}.mp3")
                ->withCustomProperties(['locale' => $locale, 'script' => $shot->voice_over])
                ->toMediaCollection(Shot::VOICE_OVERS);

            $shot->setVoiceOverTrack($locale, 'ready');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private static function track(Shot $part, string $locale): ?Media
    {
        return $part->getMedia(Shot::VOICE_OVERS)->first(fn(Media $media) => $media->getCustomProperty('locale') === $locale);
    }
}
