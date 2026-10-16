<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Resources\Public\ShotResource;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
    $this->shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::VIDEO_READY]);
});

/**
 * Gives the shot a rendered video of real frames, made with ffmpeg.
 */
function renderedVideo(Shot $shot, float $seconds = 3): void
{
    $directory = storage_path('tmp/test-' . Str::uuid());
    File::ensureDirectoryExists($directory);
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "testsrc=size=160x284:rate=24:duration={$seconds}", '-pix_fmt', 'yuv420p', "{$directory}/video.mp4"])->throw();

    $shot->addMedia("{$directory}/video.mp4")->usingFileName('shot-1.mp4')->withCustomProperties([Shot::VIDEO_SECONDS => $seconds])->toMediaCollection(Shot::VIDEO);
    File::deleteDirectory($directory);
}

function lengthOf(Shot $shot): float
{
    $video = $shot->fresh()->video();
    $path = storage_path('tmp/probe-' . Str::uuid() . '.mp4');
    File::put($path, Storage::disk($video->disk)->get($video->getPathRelativeToRoot()));
    $seconds = (float) Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output();
    File::delete($path);

    return $seconds;
}

function trimTo(mixed $test, float $start, float $end): Illuminate\Testing\TestResponse
{
    return actingAs($test->director, 'director')->post(route('public.shots.video.trim', [$test->project, $test->shot]), ['start' => $start, 'end' => $end]);
}

describe('with ffmpeg', function () {
    it('cuts the video to the part kept and keeps the rendered one to widen it again', function () {
        renderedVideo($this->shot);

        trimTo($this, 0.5, 2)->assertRedirect(route('public.shots.view', [$this->project, $this->shot]));

        $shot = $this->shot->fresh();

        expect(lengthOf($shot))->toEqualWithDelta(1.5, 0.1)
            ->and($shot->video()->getCustomProperty(Shot::VIDEO_SECONDS))->toBe(1.5)
            ->and($shot->videoOriginal()?->getCustomProperty(Shot::VIDEO_SECONDS))->toBe(3)
            ->and((new ShotResource($shot))->resolve()['videoTrim'])->toMatchArray(['seconds' => 3.0, 'start' => 0.5, 'end' => 2.0]);

        // Widening the cut takes it from the rendered video, not from the part kept before.
        trimTo($this, 0.2, 2.8)->assertRedirect();

        expect(lengthOf($this->shot))->toEqualWithDelta(2.6, 0.1)
            ->and($this->shot->fresh()->getMedia(Shot::VIDEO_ORIGINAL))->toHaveCount(1);
    });

    it('takes the rendered video back when the whole of it is kept', function () {
        renderedVideo($this->shot);
        trimTo($this, 1, 2);

        trimTo($this, 0, 3)->assertRedirect();

        $shot = $this->shot->fresh();

        expect(lengthOf($shot))->toEqualWithDelta(3, 0.1)
            ->and($shot->video()->getCustomProperty(Shot::VIDEO_TRIM))->toBeNull()
            ->and($shot->getMedia(Shot::VIDEO_ORIGINAL))->toHaveCount(0)
            ->and((new ShotResource($shot))->resolve()['videoTrim'])->toMatchArray(['start' => 0.0, 'end' => 3.0]);
    });

    it('forgets the rendered video when the video is rendered again', function () {
        renderedVideo($this->shot);
        trimTo($this, 1, 2);

        $this->shot->fresh()->forgetVideo();

        expect($this->shot->fresh()->getMedia(Shot::VIDEO_ORIGINAL))->toHaveCount(0);
    });

    it('keeps at least half a second', function () {
        renderedVideo($this->shot, 1);

        trimTo($this, 0.2, 0.4)->assertSessionHasErrors('trim');
        trimTo($this, 0.8, 0.2)->assertSessionHasErrors('end');
    });

    it('does not trim a presenter or a shot without a video', function () {
        trimTo($this, 0, 0.8)->assertSessionHasErrors('trim');

        $this->shot->forceFill(['kind' => ShotKind::PRESENTER])->save();
        renderedVideo($this->shot, 1);

        trimTo($this, 0, 0.8)->assertSessionHasErrors('trim');
        expect((new ShotResource($this->shot->fresh()))->resolve()['videoTrim'])->toBeNull();
    });

    it('does not trim while the shot is working', function () {
        $this->shot->forceFill(['status' => ShotStatus::VIDEO_PENDING])->save();
        renderedVideo($this->shot, 1);

        trimTo($this, 0, 0.8)->assertSessionHasErrors('trim');
    });
})->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');
