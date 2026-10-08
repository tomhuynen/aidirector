<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\TweakKeyframeImage;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Queue::fake();

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A planned shot with two drawn keyframes, in the given status.
 */
function busyShot(Project $project, ShotStatus $status): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'status' => $status,
        'chosen_storyline' => ['title' => 'Straightforward', 'storyline' => 'He posts the letter.'],
        'storyline' => ['keyframes' => [
            ['title' => 'At the mailbox', 'description' => 'He stands at the mailbox.'],
            ['title' => 'Posting', 'description' => 'The envelope slides into the slot.'],
        ]],
    ]);

    foreach ([1, 2] as $position) {
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position]);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
    }

    // Storing the images queues their conversions; start counting from the action itself.
    Queue::fake();

    return $shot;
}

/**
 * The actions that start the shot over, as [method, route, data].
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function restartingActions(): array
{
    return [
        'draw the keyframes again' => ['post', 'public.shots.keyframes.generate', []],
        'render the video again' => ['post', 'public.shots.video.generate', []],
    ];
}

describe('starting the shot over', function () {
    it('waits while a job works on the shot', function (string $method, string $route, array $data, ShotStatus $status) {
        $shot = busyShot($this->project, $status);

        actingAs($this->director, 'director')
            ->{$method}(route($route, [$this->project, $shot]), $data)
            ->assertSessionHasErrors();

        expect($shot->fresh()->status)->toBe($status)
            ->and($shot->keyframes()->count())->toBe(2);
        Queue::assertNothingPushed();
    })->with(restartingActions())->with([
        'drawing keyframes' => ShotStatus::KEYFRAMES_PENDING,
        'rendering the video' => ShotStatus::VIDEO_PENDING,
        'planning the storyline' => ShotStatus::STORYLINE_PENDING,
    ]);

    it('waits while one keyframe is being drawn', function (string $method, string $route, array $data) {
        $shot = busyShot($this->project, ShotStatus::KEYFRAMES_READY);
        $shot->keyframes()->where('position', 2)->update(['rendering' => true]);

        actingAs($this->director, 'director')
            ->{$method}(route($route, [$this->project, $shot]), $data)
            ->assertSessionHasErrors();

        expect($shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);
        Queue::assertNothingPushed();
    })->with(restartingActions());
});

describe('acting on one keyframe', function () {
    /**
     * @return array<string, array{0: string, 1: Closure(Keyframe): array<string, mixed>}>
     */
    $actions = fn() => [
        'edit the description' => ['public.shots.keyframes.update', fn(Keyframe $keyframe) => ['description' => 'He smiles at the mailbox.']],
        'adjust the image' => ['public.shots.keyframes.tweak', fn(Keyframe $keyframe) => ['instruction' => 'Make the mailbox brighter.']],
        'choose a version' => ['public.shots.keyframes.render', fn(Keyframe $keyframe) => ['render' => $keyframe->render_id]],
        'copy it' => ['public.shots.keyframes.copy', fn(Keyframe $keyframe) => []],
    ];

    it('waits while that keyframe is being drawn', function (string $route, Closure $data) {
        $shot = busyShot($this->project, ShotStatus::KEYFRAMES_READY);
        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();
        $keyframe->update(['rendering' => true]);

        actingAs($this->director, 'director')
            ->post(route($route, [$this->project, $shot, $keyframe]), $data($keyframe))
            ->assertSessionHasErrors();

        expect($shot->keyframes()->count())->toBe(2)
            ->and($keyframe->fresh()->description)->toBe($keyframe->description);
        Queue::assertNothingPushed();
    })->with($actions);

    it('waits while the shot renders its video', function (string $route, Closure $data) {
        $shot = busyShot($this->project, ShotStatus::VIDEO_PENDING);
        $keyframe = $shot->keyframes()->where('position', 2)->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route($route, [$this->project, $shot, $keyframe]), $data($keyframe))
            ->assertSessionHasErrors();

        expect($keyframe->fresh()->rendering)->toBeFalse()
            ->and($shot->keyframes()->count())->toBe(2);
        Queue::assertNothingPushed();
    })->with($actions);

    it('adjusts one keyframe while another is being adjusted', function () {
        $shot = busyShot($this->project, ShotStatus::KEYFRAMES_READY);
        $shot->keyframes()->where('position', 1)->update(['rendering' => true]);
        $second = $shot->keyframes()->where('position', 2)->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.tweak', [$this->project, $shot, $second]), ['instruction' => 'Make the mailbox brighter.'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(TweakKeyframeImage::class, fn(TweakKeyframeImage $job) => $job->keyframe->is($second));
    });

    it('chooses a version only once every keyframe is drawn', function () {
        $shot = busyShot($this->project, ShotStatus::FIRST_KEYFRAME_READY);
        $first = $shot->keyframes()->where('position', 1)->firstOrFail();

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.render', [$this->project, $shot, $first]), ['render' => $first->render_id])
            ->assertSessionHasErrors('render');

        Queue::assertNothingPushed();
    });
});
