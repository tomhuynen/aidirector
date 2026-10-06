<?php

declare(strict_types=1);

namespace App\Support\Video;

use App\Enums\ShotTransition;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Joins video clips into one with ffmpeg: a hard cut, a crossfade or a fade
 * through black between them. Every clip is first brought to the size and
 * frame rate of the first one, and clips without sound get silence, so
 * clips from different renders still fit together.
 */
class ClipJoiner
{
    /**
     * The longest a transition lasts; shorter when a clip is too short for it.
     */
    public const FADE_SECONDS = 0.5;

    /**
     * @param  list<string>  $paths  local clip files, in order
     * @return array{starts: list<float>, duration: float} when each clip starts in the joined video, and its length, in seconds
     *
     * @throws RuntimeException when ffprobe or ffmpeg fails
     */
    public function join(array $paths, ShotTransition $transition, string $output): array
    {
        if (count($paths) < 2) {
            throw new RuntimeException('At least two clips are needed to join.');
        }

        $clips = array_map(fn(string $path) => $this->probe($path), $paths);
        $width = $clips[0]['width'];
        $height = $clips[0]['height'];
        $fps = $clips[0]['fps'];
        $withSound = in_array(true, array_column($clips, 'audio'), true);
        $fade = min(self::FADE_SECONDS, min(array_column($clips, 'duration')) / 2);

        $filters = [];

        foreach ($clips as $i => $clip) {
            $filters[] = "[{$i}:v]scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps={$fps},format=yuv420p,settb=AVTB[v{$i}]";

            if ($withSound) {
                $filters[] = $clip['audio']
                    ? "[{$i}:a]aformat=sample_rates=44100:channel_layouts=stereo,apad,atrim=0:{$clip['duration']},asetpts=PTS-STARTPTS[a{$i}]"
                    : "anullsrc=channel_layout=stereo:sample_rate=44100,atrim=0:{$clip['duration']}[a{$i}]";
            }
        }

        $xfade = $transition->xfade();
        $overlap = $xfade === null ? 0.0 : $fade;
        $starts = [];
        $start = 0.0;

        foreach ($clips as $clip) {
            $starts[] = round($start, 3);
            $start += $clip['duration'] - $overlap;
        }

        if ($xfade === null) {
            $inputs = implode('', array_map(fn(int $i) => "[v{$i}]" . ($withSound ? "[a{$i}]" : ''), array_keys($clips)));
            $filters[] = $inputs . 'concat=n=' . count($clips) . ':v=1:a=' . ($withSound ? 1 : 0) . '[v]' . ($withSound ? '[a]' : '');
        } else {
            $video = 'v0';
            $audio = 'a0';
            $offset = 0.0;

            foreach (array_slice($clips, 1, null, true) as $i => $clip) {
                $offset += $clips[$i - 1]['duration'] - $fade;
                $nextVideo = $i === count($clips) - 1 ? 'v' : "x{$i}";
                $filters[] = sprintf('[%s][v%d]xfade=transition=%s:duration=%s:offset=%s[%s]', $video, $i, $xfade, $this->number($fade), $this->number($offset), $nextVideo);
                $video = $nextVideo;

                if ($withSound) {
                    $nextAudio = $i === count($clips) - 1 ? 'a' : "y{$i}";
                    $filters[] = sprintf('[%s][a%d]acrossfade=d=%s[%s]', $audio, $i, $this->number($fade), $nextAudio);
                    $audio = $nextAudio;
                }
            }
        }

        $command = [Config::get('pipeline.ffmpeg.binary'), '-y', '-v', 'error'];

        foreach ($paths as $path) {
            array_push($command, '-i', $path);
        }

        array_push($command, '-filter_complex', implode(';', $filters), '-map', '[v]');
        array_push($command, ...($withSound ? ['-map', '[a]', '-c:a', 'aac', '-b:a', '128k'] : ['-an']));
        array_push($command, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $output);

        $result = Process::timeout(600)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg could not join the clips: ' . mb_substr(trim($result->errorOutput()), -500));
        }

        return ['starts' => $starts, 'duration' => round($start + $overlap, 3)];
    }

    /**
     * @return array{width: int, height: int, fps: string, duration: float, audio: bool}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(60)->run([
            Config::get('pipeline.ffmpeg.ffprobe'), '-v', 'error',
            '-show_entries', 'stream=codec_type,width,height,r_frame_rate:format=duration',
            '-of', 'json', $path,
        ]);

        /** @var array{streams?: list<array{codec_type?: string, width?: int, height?: int, r_frame_rate?: string}>, format?: array{duration?: string}}|null $info */
        $info = json_decode($result->output(), true);
        $streams = collect($info['streams'] ?? []);
        $video = $streams->firstWhere('codec_type', 'video');
        $duration = (float) ($info['format']['duration'] ?? 0);

        if (! $result->successful() || $video === null || $duration <= 0) {
            throw new RuntimeException("ffprobe could not read the clip {$path}: " . mb_substr(trim($result->errorOutput()), -300));
        }

        return [
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'fps' => (string) ($video['r_frame_rate'] ?? '30/1'),
            'duration' => $duration,
            'audio' => $streams->contains('codec_type', 'audio'),
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
