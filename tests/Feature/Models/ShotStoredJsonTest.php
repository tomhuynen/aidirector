<?php

declare(strict_types=1);

use App\Ai\Agents\ShotReviewer;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotStatus;
use App\Jobs\ReviewShot;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Facades\Storage;

/*
 * Jobs and requests on the same shot each hold a copy loaded earlier. A write
 * to the plan, the review or the voice-over tracks must add to what is stored,
 * not put that old copy back.
 */

it('keeps the plan change of another job made after this copy was loaded', function () {
    $shot = Shot::factory()->create(['storyline' => ['framing' => ['size' => 'full'], 'keyframes' => [
        ['title' => 'Arrive', 'description' => 'She arrives.'],
        ['title' => 'Leave', 'description' => 'She leaves.'],
    ]]]);
    $first = Shot::query()->findOrFail($shot->id);
    $second = Shot::query()->findOrFail($shot->id);

    $first->updatePlannedKeyframe(1, ['description' => 'She walks in from the left.']);
    $second->updatePlannedKeyframe(2, ['description' => 'She walks into the hall.'], ['must_show']);

    expect(array_column($shot->fresh()->storylineKeyframes(), 'description'))->toBe(['She walks in from the left.', 'She walks into the hall.'])
        ->and($shot->fresh()->storyline['framing'])->toBe(['size' => 'full'])
        ->and(array_column($second->storylineKeyframes(), 'description'))->toBe(['She walks in from the left.', 'She walks into the hall.']);
});

it('keeps the voice-over track of another language that finished at the same time', function () {
    $shot = Shot::factory()->create(['voice_over_tracks' => ['nl-NL' => ['status' => 'pending'], 'en-GB' => ['status' => 'pending']]]);
    $dutch = Shot::query()->findOrFail($shot->id);
    $english = Shot::query()->findOrFail($shot->id);

    $dutch->setVoiceOverTrack('nl-NL', 'ready');
    $english->setVoiceOverTrack('en-GB', 'ready');

    expect($shot->fresh()->voice_over_tracks)->toEqualCanonicalizing(['nl-NL' => ['status' => 'ready'], 'en-GB' => ['status' => 'ready']]);
});

it('keeps what the director resolved while the review ran', function () {
    Storage::fake(Disk::TENANT->value);
    $shot = Shot::factory()->create([
        'status' => ShotStatus::KEYFRAMES_READY,
        'keyframe_review' => ['clear' => false, 'notes' => [['text' => 'The sign is too small.', 'keyframes' => [2]]]],
    ]);

    foreach ([1, 2] as $position) {
        $image = imagecreatetruecolor(16, 9);
        ob_start();
        imagepng($image);
        $keyframe = Keyframe::factory()->for($shot)->create(['position' => $position]);
        $render = $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName("k{$position}.png")->toMediaCollection(Keyframe::RENDERS);
        $keyframe->forceFill(['render_id' => $render->id])->save();
    }

    $job = new ReviewShot(Shot::query()->findOrFail($shot->id));

    // While the reviewer looks, the director dismisses an issue in another request.
    ShotReviewer::fake(function () use ($shot) {
        Shot::query()->findOrFail($shot->id)->updateStoredJson('keyframe_review', fn(array $review) => [...$review, 'resolved' => ['sign' => [2]]]);

        return ['clear' => false, 'notes' => [['keyframes' => [2], 'note' => 'The sign is too small.']]];
    });

    $job->handle(app(KeyframePainter::class));

    expect($shot->fresh()->keyframe_review['resolved'])->toBe(['sign' => [2]]);
});
