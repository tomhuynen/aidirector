<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Jobs\MergeShotAudio;
use App\Jobs\MergeShotVideos;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Audio\AudioLength;
use App\Support\Projects\ProjectSettings;
use App\Support\Video\ClipJoiner;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake([MergeShotVideos::class]);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create(['default_duration' => 5]);
});

function stubClip(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
}

/**
 * Shots 1 to $count in the sequence, each with a video unless left out.
 *
 * @param  list<int>  $withoutVideo
 * @return list<Shot>
 */
function sequence(Project $project, int $count, array $withoutVideo = []): array
{
    return collect(range(1, $count))->map(function (int $position) use ($project, $withoutVideo) {
        $shot = Shot::factory()->for($project)->create([
            'position' => $position,
            'title' => "Shot {$position}",
            'takeaway' => "Takeaway {$position}",
            'status' => in_array($position, $withoutVideo, true) ? ShotStatus::KEYFRAMES_READY : ShotStatus::VIDEO_READY,
        ]);

        if (! in_array($position, $withoutVideo, true)) {
            $shot->addMediaFromString(stubClip())->usingFileName("shot-{$position}.mp4")->toMediaCollection(Shot::VIDEO);
        }

        return $shot;
    })->all();
}

describe('merging', function () {
    it('stores merged videos larger than an upload may be', function () {
        $shot = Shot::factory()->for($this->project)->create();

        $shot->addMediaFromString(stubClip() . str_repeat("\x00", 20 * 1024 * 1024))->usingFileName('merged.mp4')->toMediaCollection(Shot::VIDEO);

        expect($shot->video()->size)->toBeGreaterThan(20 * 1024 * 1024);
    });

    it('merges more than twenty shots at once', function () {
        $shots = sequence($this->project, 25);

        actingAs($this->director, 'director')
            ->post(route('public.shots.merge', $this->project), [
                'shots' => array_map(fn(Shot $shot) => $shot->sqid, $shots),
                'title' => 'Everything',
                'transition' => 'cut',
            ])
            ->assertSessionHasNoErrors();

        expect($this->project->shots()->sole()->parts()->count())->toBe(25);
    });

    it('merges adjacent shots into one in their place and joins their clips', function () {
        [$one, $two, $three, $four, $five] = sequence($this->project, 5);

        $response = actingAs($this->director, 'director')
            ->post(route('public.shots.merge', $this->project), [
                'shots' => [$four->sqid, $two->sqid, $three->sqid],
                'title' => 'Key handling',
                'transition' => 'fade-black',
            ]);

        $merged = $this->project->shots()->where('title', 'Key handling')->sole();
        $response->assertRedirect(route('public.shots.view', [$this->project, $merged]));

        expect($this->project->shots()->pluck('title')->all())->toBe(['Shot 1', 'Key handling', 'Shot 5'])
            ->and($this->project->shots()->pluck('position')->all())->toBe([1, 2, 3])
            ->and($merged->merge_transition)->toBe(ShotTransition::FADE_BLACK)
            ->and($merged->status)->toBe(ShotStatus::VIDEO_PENDING)
            ->and($merged->duration)->toBe(15)
            ->and($merged->takeaway)->toBe('Takeaway 2')
            ->and($merged->parts()->pluck('title')->all())->toBe(['Shot 2', 'Shot 3', 'Shot 4']);

        Queue::assertPushed(MergeShotVideos::class, fn(MergeShotVideos $job) => $job->shot->is($merged));
    });

    it('refuses shots that do not follow each other, lack a video or are merged already', function (Closure $pick, string $message) {
        $shots = sequence($this->project, 4, withoutVideo: [4]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.merge', $this->project), [
                'shots' => $pick($shots),
                'title' => 'Merged',
                'transition' => 'cut',
            ])
            ->assertSessionHasErrors(['shots' => $message]);

        expect($this->project->shots()->count())->toBe(4);
        Queue::assertNothingPushed();
    })->with([
        'apart' => [fn(array $shots) => [$shots[0]->sqid, $shots[2]->sqid], 'Only shots that follow each other can be merged.'],
        'no video' => [fn(array $shots) => [$shots[2]->sqid, $shots[3]->sqid], 'Every shot needs a video before it can be merged.'],
        'one shot' => [fn(array $shots) => [$shots[0]->sqid], 'The shots field must have at least 2 items.'],
    ]);

    it('does not merge a merged shot again', function () {
        sequence($this->project, 3);
        $merged = Shot::factory()->for($this->project)->create(['position' => 4, 'merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_READY]);
        $merged->addMediaFromString(stubClip())->usingFileName('m.mp4')->toMediaCollection(Shot::VIDEO);
        $third = $this->project->shots()->where('position', 3)->sole();

        actingAs($this->director, 'director')
            ->post(route('public.shots.merge', $this->project), ['shots' => [$third->sqid, $merged->sqid], 'title' => 'Again', 'transition' => 'cut'])
            ->assertSessionHasErrors(['shots' => 'A merged shot cannot be merged again. Unmerge it first.']);
    });

    it('forbids merging another director\'s shots', function () {
        [$one, $two] = sequence($this->project, 2);

        actingAs(Director::factory()->create(), 'director')
            ->post(route('public.shots.merge', $this->project), ['shots' => [$one->sqid, $two->sqid], 'title' => 'Mine', 'transition' => 'cut'])
            ->assertForbidden();
    });
});

describe('merged shot', function () {
    beforeEach(function () {
        [$this->one, $this->two, $this->three, $this->four] = sequence($this->project, 4);
        Keyframe::factory()->for($this->two)->create(['position' => 1]);

        actingAs($this->director, 'director')->post(route('public.shots.merge', $this->project), [
            'shots' => [$this->two->sqid, $this->three->sqid],
            'title' => 'Merged',
            'transition' => 'crossfade',
        ]);

        $this->merged = $this->project->shots()->where('title', 'Merged')->sole();
    });

    it('shows its parts and transitions, and keeps the parts reachable', function () {
        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $this->merged]))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->where('merge.transition', 'crossfade')
                ->where('merge.parts.0.title', 'Shot 2')
                ->where('merge.parts.1.url', route('public.shots.view', [$this->project, $this->three]))
                ->where('merge.links.unmerge', route('public.shots.unmerge', [$this->project, $this->merged]))
                ->has('shotTransitions', 3)
                ->has('siblings', 3)
                ->where('siblings.1.partsCount', 2)
                ->where('mergedInto', null));

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $this->three]))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page
                ->where('merge', null)
                ->where('mergedInto.title', 'Merged')
                ->where('mergedInto.url', route('public.shots.view', [$this->project, $this->merged])));
    });

    it('joins the clips again with another transition', function () {
        actingAs($this->director, 'director')
            ->post(route('public.shots.merge.update', [$this->project, $this->merged]), ['transition' => 'fade-black'])
            ->assertRedirect(route('public.shots.view', [$this->project, $this->merged]));

        expect($this->merged->fresh())
            ->merge_transition->toBe(ShotTransition::FADE_BLACK)
            ->status->toBe(ShotStatus::VIDEO_PENDING);

        Queue::assertPushed(MergeShotVideos::class, 2);
    });

    it('unmerges: the parts come back in its place, untouched', function () {
        $this->merged->addMediaFromString(stubClip())->usingFileName('merged.mp4')->toMediaCollection(Shot::VIDEO);

        actingAs($this->director, 'director')
            ->delete(route('public.shots.unmerge', [$this->project, $this->merged]))
            ->assertRedirect(route('public.shots.view', [$this->project, $this->two]));

        expect($this->project->shots()->pluck('title')->all())->toBe(['Shot 1', 'Shot 2', 'Shot 3', 'Shot 4'])
            ->and($this->project->shots()->pluck('position')->all())->toBe([1, 2, 3, 4])
            ->and(Shot::query()->find($this->merged->id))->toBeNull()
            ->and($this->two->fresh()->keyframes()->count())->toBe(1)
            ->and($this->two->fresh()->video())->not->toBeNull();
    });

    it('takes its parts along when the merged shot is deleted', function () {
        actingAs($this->director, 'director')
            ->delete(route('public.shots.destroy', [$this->project, $this->merged]));

        expect(Shot::query()->whereKey([$this->two->id, $this->three->id])->count())->toBe(0)
            ->and($this->project->shots()->pluck('position')->all())->toBe([1, 2]);
    });

    it('marks the merged shot failed when the clips cannot be joined', function () {
        (new MergeShotVideos($this->merged))->failed(new RuntimeException('ffmpeg missing'));

        expect($this->merged->fresh())
            ->status->toBe(ShotStatus::KEYFRAMES_READY)
            ->video_error->toBe('The clips could not be joined. Please try again.');
        expect($this->director->notifications()->sole()->data['failed'])->toBeTrue();
    });
});

describe('joining', function () {
    it('joins real clips with ffmpeg and stores them on the merged shot', function () {
        $directory = storage_path('framework/testing/clips');
        @mkdir($directory, 0777, true);

        foreach ([1, 2] as $n) {
            Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=1', '-pix_fmt', 'yuv420p', "{$directory}/clip-{$n}.mp4"])->throw();
        }

        $parts = collect([1, 2])->map(function (int $n) {
            $shot = Shot::factory()->for($this->project)->create(['position' => $n, 'status' => ShotStatus::VIDEO_READY]);
            $shot->addMedia(storage_path("framework/testing/clips/clip-{$n}.mp4"))->preservingOriginal()->toMediaCollection(Shot::VIDEO);

            return $shot;
        });
        $merged = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::VIDEO_PENDING, 'merge_transition' => ShotTransition::FADE_BLACK]);
        $parts->each(fn(Shot $part) => $part->forceFill(['merged_into_id' => $merged->id])->save());

        (new MergeShotVideos($merged))->handle(app(ClipJoiner::class));

        $merged->refresh();

        expect($merged->status)->toBe(ShotStatus::VIDEO_READY)
            ->and($merged->video())->not->toBeNull()
            ->and($this->director->notifications()->sole()->data['title'])->toContain('merged video');
    })->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');

    it('merges the parts\' audio tracks, each where its part starts', function () {
        $directory = storage_path('framework/testing/clips');
        @mkdir($directory, 0777, true);

        foreach ([1, 2] as $n) {
            Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=160x284:rate=24:duration=2', '-pix_fmt', 'yuv420p', "{$directory}/clip-{$n}.mp4"])->throw();
            Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', "{$directory}/voice-{$n}.mp3"])->throw();
        }

        $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['nl-NL']);
        $this->project->save();

        $parts = collect([1, 2])->map(function (int $n) {
            $shot = Shot::factory()->for($this->project)->create(['position' => $n, 'status' => ShotStatus::VIDEO_READY, 'voice_over' => "Line {$n}."]);
            $shot->addMedia(storage_path("framework/testing/clips/clip-{$n}.mp4"))->preservingOriginal()->toMediaCollection(Shot::VIDEO);
            $shot->addMedia(storage_path("framework/testing/clips/voice-{$n}.mp3"))->preservingOriginal()->withCustomProperties(['locale' => 'nl-NL'])->toMediaCollection(Shot::VOICE_OVERS);

            return $shot;
        });
        $merged = Shot::factory()->for($this->project)->create(['position' => 1, 'status' => ShotStatus::VIDEO_PENDING, 'merge_transition' => ShotTransition::CUT]);
        $parts->each(fn(Shot $part) => $part->forceFill(['merged_into_id' => $merged->id])->save());

        (new MergeShotVideos($merged))->handle(app(ClipJoiner::class));

        $merged->refresh();
        $track = $merged->getFirstMedia(Shot::VOICE_OVERS);

        expect($merged->video()->getCustomProperty(Shot::PART_STARTS))->toBe([0, 2])
            ->and($merged->voice_over)->toBe('Line 1. Line 2.')
            ->and($merged->voice_over_tracks)->toBe(['nl-NL' => ['status' => 'ready']])
            ->and($track->getCustomProperty('locale'))->toBe('nl-NL')
            ->and(app(AudioLength::class)->seconds((string) file_get_contents($track->getPath())))->toEqualWithDelta(4.0, 0.1);
    })->skip(fn() => ! Process::run(['ffmpeg', '-version'])->successful(), 'ffmpeg is not installed');
});

it('merges a part\'s audio again when its track is spoken again', function () {
    Queue::fake([MergeShotAudio::class]);
    Storage::fake(Disk::TENANT->value);
    $this->project->settings = new ProjectSettings(voiceOver: true, voiceOverLocales: ['nl-NL', 'en-GB']);
    $this->project->save();

    $merged = Shot::factory()->for($this->project)->create(['merge_transition' => ShotTransition::CUT, 'status' => ShotStatus::VIDEO_READY]);
    $merged->addMediaFromString("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64))->usingFileName('merged.mp4')->toMediaCollection(Shot::VIDEO);
    $part = Shot::factory()->for($this->project)->create(['merged_into_id' => $merged->id]);
    $part->addMediaFromString("ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x64" . str_repeat("\x00", 400))->usingFileName('nl.mp3')->withCustomProperties(['locale' => 'nl-NL'])->toMediaCollection(Shot::VOICE_OVERS);

    MergeShotAudio::start($merged->fresh(), ['nl-NL']);

    Queue::assertPushed(MergeShotAudio::class, fn(MergeShotAudio $job) => $job->locales === ['nl-NL']);
    expect($merged->fresh()->voice_over_tracks)->toBe(['nl-NL' => ['status' => 'pending']]);
});
