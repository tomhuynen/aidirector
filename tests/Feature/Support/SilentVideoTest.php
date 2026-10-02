<?php

declare(strict_types=1);

use App\Support\Video\SilentVideo;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

it('removes the audio track and keeps the picture', function () {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

    if ($ffmpeg === '') {
        $this->markTestSkipped('ffmpeg is not installed.');
    }

    Config::set('pipeline.video.ffmpeg', $ffmpeg);
    $clip = tempnam(sys_get_temp_dir(), 'test-') . '.mp4';
    Process::run([$ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=1', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-shortest', '-c:v', 'libx264', '-c:a', 'aac', $clip])->throw();

    $silent = tempnam(sys_get_temp_dir(), 'test-') . '.mp4';
    file_put_contents($silent, app(SilentVideo::class)->strip((string) file_get_contents($clip)));

    $streams = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $silent])->output();

    expect(trim($streams))->toBe('video');

    @unlink($clip);
    @unlink($silent);
});

it('keeps the clip as it is when ffmpeg fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

    expect(app(SilentVideo::class)->strip('original bytes'))->toBe('original bytes');
});
