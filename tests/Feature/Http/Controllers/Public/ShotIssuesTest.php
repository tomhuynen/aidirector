<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\DrawKeyframe;
use App\Jobs\FixKeyframe;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateStoryline;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\ShotIssues;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

describe('retry', function () {
    it('draws the failed keyframes again', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);
        $failed = Keyframe::factory()->for($shot)->create(['position' => 2, 'render_error' => 'Gateway error']);
        $fine = Keyframe::factory()->for($shot)->create(['position' => 3]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($failed->fresh()->isBusy())->toBeTrue()->and($failed->fresh())->render_error->toBeNull();
        Queue::assertPushed(DrawKeyframe::class, 1);
        Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->is($failed));
        expect($fine->fresh()->isBusy())->toBeFalse();
    });

    it('draws the options for keyframe 1 again when none could be drawn', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::STORYLINE_READY]);
        Keyframe::factory()->for($shot)->create(['position' => 1, 'render_error' => 'No option could be drawn.']);

        actingAs($this->director, 'director')->post(route('public.shots.retry', [$this->project, $shot]));

        expect($shot->fresh()->status)->toBe(ShotStatus::FIRST_KEYFRAME_PENDING);
        Queue::assertPushed(GenerateKeyframes::class);
    });

    it('plans the shot again when that failed', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::DRAFT, 'storyline_error' => 'Timed out']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        expect($shot->fresh())->status->toBe(ShotStatus::STORYLINE_PENDING)->storyline_error->toBeNull();
        Queue::assertPushed(GenerateStoryline::class);
    });

    it('refuses when nothing failed', function () {
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::KEYFRAMES_READY]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.retry', [$this->project, $shot]))
            ->assertSessionHasErrors('retry');

        Queue::assertNothingPushed();
    });
});

/**
 * A shot ready to render whose four keyframes are drawn, with the given review notes.
 *
 * @param  list<string>  $notes
 */
function reviewedShot(Project $project, array $notes): Shot
{
    Storage::fake(Disk::TENANT->value);

    $shot = Shot::factory()->for($project)->create(['status' => ShotStatus::KEYFRAMES_READY, 'keyframe_review' => ['clear' => $notes === [], 'notes' => $notes]]);

    foreach (range(1, 4) as $position) {
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        Keyframe::factory()->for($shot)->create(['position' => $position])
            ->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
    }

    return $shot;
}

describe('issues', function () {
    it('groups the issues by the keyframes they name', function () {
        $shot = reviewedShot($this->project, [
            'In keyframes 1 through 3 a padlock already hangs on the panel.',
            'In keyframes 2 and 4 she still stands on the line.',
            'The point of the shot is unclear.',
        ]);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_ISSUES, ['The hand does not point at the valve.'])->save();

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        // Keyframe 1 is what the others match, so a note naming it with others changes the others.
        expect(array_column($groups, 'key'))->toBe(['keyframe-2', 'keyframe-3', 'keyframe-4', 'shot'])
            ->and($groups[0]['issues'])->toBe([
                'In keyframes 1 through 3 a padlock already hangs on the panel.',
                'In keyframes 2 and 4 she still stands on the line.',
                'The check found: The hand does not point at the valve.',
            ])
            ->and(array_column($groups, 'fixable'))->toBe([true, true, true, false]);
    });

    it('groups notes by the keyframes the reviewer gives and shows them per keyframe in the editor', function () {
        $shot = reviewedShot($this->project, []);
        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => [
            ['text' => 'She is not there yet, so noticing the sign cannot be seen.', 'keyframes' => [1]],
            ['text' => 'The floor line runs at another angle than in keyframe 1.', 'keyframes' => [3, 9]],
            ['text' => 'The point of the shot is unclear.', 'keyframes' => []],
        ]]]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page
                ->has('shot.issueGroups', 3)
                ->where('shot.issueGroups.0.position', 1)
                ->where('shot.issueGroups.0.issues', ['She is not there yet, so noticing the sign cannot be seen.'])
                ->where('shot.issueGroups.0.fixUrl', route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-1']))
                ->where('shot.issueGroups.1.position', 3)
                ->where('shot.issueGroups.1.issues', ['The floor line runs at another angle than in keyframe 1.'])
                ->where('shot.issueGroups.2.position', null)
                ->where('shot.issueGroups.2.fixUrl', null)
                ->where('shot.issueGroups.2.dismissUrl', route('public.shots.issues.dismiss', [$this->project, $shot, 'shot'])));
    });

    it('changes the other keyframes, not keyframe 1, when the reviewer names keyframe 1 with others', function () {
        $shot = reviewedShot($this->project, []);
        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => [
            ['text' => 'The floor line runs at another angle.', 'keyframes' => [1, 3]],
        ]]]);

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        expect(array_column($groups, 'position'))->toBe([3]);
    });

    it('fixes one keyframe with all its issues and keeps a shared note for the other keyframes', function () {
        $shot = reviewedShot($this->project, ['In keyframes 2 and 3 the padlock already hangs.']);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_ISSUES, ['The hand does not point at the valve.'])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-2']))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        Queue::assertPushed(FixKeyframe::class, 1);
        Queue::assertPushed(FixKeyframe::class, fn(FixKeyframe $job) => $job->keyframe->position === 2
            && $job->version === $shot->keyframes()->where('position', 2)->first()->draw_version
            && $job->problems === ['In keyframes 2 and 3 the padlock already hangs.', 'The hand does not point at the valve.']);
        Queue::assertNotPushed(TweakKeyframeImage::class);

        $groups = app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get());

        expect(array_column($groups, 'key'))->toBe(['keyframe-3']);
    });

    it('draws a keyframe again from its description instead of fixing it', function () {
        $shot = reviewedShot($this->project, ['In keyframe 2 the padlock already hangs.']);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.redraw', [$this->project, $shot, 'keyframe-2']))
            ->assertRedirect();

        Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->position === 2);
        Queue::assertNotPushed(FixKeyframe::class);
        expect(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()))->toBe([]);
    });

    it('dismisses the issues of a keyframe or of the whole shot without redrawing anything', function () {
        $shot = reviewedShot($this->project, ['In keyframe 4 the bulge is subtle.', 'The point is unclear.']);
        $shot->update(['video_error' => 'The video model failed.']);

        actingAs($this->director, 'director')->post(route('public.shots.issues.dismiss', [$this->project, $shot, 'keyframe-4']));
        actingAs($this->director, 'director')->post(route('public.shots.issues.dismiss', [$this->project, $shot, 'shot']));

        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect($shot->fresh()->video_error)->toBeNull()
            ->and(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()))->toBe([]);
    });

    it('fixes every keyframe at once and leaves the issues of the whole shot', function () {
        $shot = reviewedShot($this->project, [
            'In keyframes 1 and 2 she looks almost the same.',
            'In keyframes 4 and 5 the helmet gains the word DAMEN.',
            'The point is unclear.',
        ]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        Queue::assertPushed(FixKeyframe::class, 2);
        Queue::assertPushed(FixKeyframe::class, fn(FixKeyframe $job) => $job->keyframe->position === 2);
        Queue::assertPushed(FixKeyframe::class, fn(FixKeyframe $job) => $job->keyframe->position === 4);
        expect(array_column(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()), 'key'))->toBe(['shot']);
        // The editor shows only what is left.
        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.issueGroups.0.key', 'shot')->where('shot.issueGroups.0.issues', ['The point is unclear.'])->has('shot.issueGroups', 1));
    });

    it('offers Fix all on the shot only when a keyframe can be redrawn', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the bin is gone.']);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.fixableIssues', true)->where('shot.links.issuesFixAll', route('public.shots.issues.fix-all', [$this->project, $shot])));

        $shot->update(['keyframe_review' => ['clear' => false, 'notes' => ['The point is unclear.']]]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]))
            ->assertSessionHasErrors('issue');
    });

    it('dismisses every issue at once without redrawing anything', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the bin is gone.', 'The point is unclear.']);
        $shot->keyframes()->where('position', 2)->first()->render()->setCustomProperty(Keyframe::CHECK_ISSUES, ['The hand does not point at the valve.'])->save();

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.dismiss-all', [$this->project, $shot]))
            ->assertRedirect(route('public.shots.view', [$this->project, $shot]));

        Queue::assertNotPushed(TweakKeyframeImage::class);
        expect(app(ShotIssues::class)->groups($shot->fresh(), $shot->keyframes()->with('media')->get()))->toBe([]);
        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.issueGroups', []));
    });

    it('offers Fix all after the video is rendered too, so the video can be made again', function () {
        $shot = reviewedShot($this->project, ['In keyframe 3 the floor line runs at another angle than in keyframe 1.']);
        $shot->update(['status' => ShotStatus::VIDEO_READY]);

        actingAs($this->director, 'director')
            ->get(route('public.shots.view', [$this->project, $shot]))
            ->assertInertia(fn($page) => $page->where('shot.fixableIssues', true));

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix-all', [$this->project, $shot]))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(FixKeyframe::class, fn(FixKeyframe $job) => $job->keyframe->position === 3);
    });

    it('refuses issues that are gone', function () {
        $shot = reviewedShot($this->project, []);

        actingAs($this->director, 'director')
            ->post(route('public.shots.issues.fix', [$this->project, $shot, 'keyframe-1']))
            ->assertSessionHasErrors('issue');
    });
});
