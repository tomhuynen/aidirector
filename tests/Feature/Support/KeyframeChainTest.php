<?php

declare(strict_types=1);

use App\Enums\KeyframeStatus;
use App\Enums\ShotStatus;
use App\Jobs\DrawKeyframe;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use App\Support\Shots\KeyframeChain;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([DrawKeyframe::class]);
    $this->chain = app(KeyframeChain::class);
    $this->shot = Shot::factory()->for(Project::factory())->create(['status' => ShotStatus::KEYFRAMES_PENDING]);
    $this->keyframes = collect(range(1, 3))->map(fn(int $position) => Keyframe::factory()->for($this->shot)->create(['position' => $position]));
    $this->chain->link($this->shot);
});

/**
 * @return list<string>
 */
function statuses(Shot $shot): array
{
    return $shot->keyframes()->orderBy('position')->get()->map(fn(Keyframe $keyframe) => $keyframe->status->value)->all();
}

it('links every keyframe to the one before it', function () {
    [$first, $second, $third] = $this->keyframes->map->fresh()->all();

    expect($first->depends_on_id)->toBeNull()
        ->and($second->depends_on_id)->toBe($first->id)
        ->and($third->depends_on_id)->toBe($second->id);
});

it('links keyframe 1 of a shot that continues another to that shot\'s last keyframe', function () {
    $next = Shot::factory()->for($this->shot->project)->create(['position' => $this->shot->position + 1, 'storyline' => ['setting_from' => ['shot_id' => $this->shot->id, 'keyframe' => Shot::LAST_KEYFRAME]]]);
    $first = Keyframe::factory()->for($next)->create(['position' => 1]);

    $this->chain->link($next);

    expect($first->fresh()->depends_on_id)->toBe($this->keyframes->last()->id);
});

it('draws keyframes one after another, each once the one before is ready', function () {
    $this->keyframes->first()->forceFill(['status' => KeyframeStatus::READY])->save();

    $this->chain->draw($this->keyframes->skip(1)->values());

    expect(statuses($this->shot))->toBe(['ready', 'drawing', 'waiting']);
    Queue::assertPushed(DrawKeyframe::class, 1);
    Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->position === 2);

    $this->chain->finished($this->keyframes[1]->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'ready', 'drawing']);
    Queue::assertPushed(DrawKeyframe::class, fn(DrawKeyframe $job) => $job->keyframe->position === 3);

    $this->chain->finished($this->keyframes[2]->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'ready', 'ready'])
        ->and($this->shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);
});

it('starts a keyframe only once, however often its turn is asked for', function () {
    $this->keyframes->first()->forceFill(['status' => KeyframeStatus::READY])->save();
    $this->chain->draw($this->keyframes[1]);

    $this->chain->advance($this->shot->id);
    $this->chain->advance($this->shot->id);

    Queue::assertPushed(DrawKeyframe::class, 1);
});

it('draws only the changed keyframe again, unless the director asks for the ones after it too', function () {
    $this->keyframes->each(fn(Keyframe $keyframe) => $keyframe->forceFill(['status' => KeyframeStatus::READY, 'render_id' => 1])->save());

    $this->chain->redrawFrom($this->keyframes[1]->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'drawing', 'ready']);

    $this->chain->finished($this->keyframes[1]->fresh());
    $this->chain->redrawFrom($this->keyframes[1]->fresh(), following: true);

    expect(statuses($this->shot))->toBe(['ready', 'drawing', 'waiting']);

    $this->chain->finished($this->keyframes[1]->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'ready', 'drawing']);
});

it('always draws a keyframe after a changed one that was never drawn', function () {
    $this->keyframes->take(2)->each(fn(Keyframe $keyframe) => $keyframe->forceFill(['status' => KeyframeStatus::READY, 'render_id' => 1])->save());

    $this->chain->changed($this->keyframes[1]->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'ready', 'drawing']);
});

it('also draws keyframe 1 of the shot that continues from a changed keyframe, when asked', function () {
    $this->keyframes->each(fn(Keyframe $keyframe) => $keyframe->forceFill(['status' => KeyframeStatus::READY, 'render_id' => 1])->save());
    $next = Shot::factory()->for($this->shot->project)->create(['position' => $this->shot->position + 1, 'status' => ShotStatus::KEYFRAMES_READY, 'storyline' => ['setting_from' => ['shot_id' => $this->shot->id, 'keyframe' => Shot::LAST_KEYFRAME]]]);
    $continued = Keyframe::factory()->for($next)->create(['position' => 1, 'status' => KeyframeStatus::READY, 'render_id' => 1]);
    $this->chain->link($next);

    $this->chain->changed($this->keyframes[2]->fresh());

    expect($continued->fresh()->status)->toBe(KeyframeStatus::READY);

    $this->chain->changed($this->keyframes[2]->fresh(), following: true);

    expect($continued->fresh()->status)->toBe(KeyframeStatus::DRAWING);
});

it('ignores a draw that was asked for again meanwhile', function () {
    $this->keyframes->first()->forceFill(['status' => KeyframeStatus::READY])->save();
    $this->chain->draw($this->keyframes[1]);
    $old = $this->keyframes[1]->fresh()->draw_version;

    $this->chain->redrawFrom($this->keyframes[1]->fresh());

    expect($this->chain->wanted($this->keyframes[1], $old))->toBeFalse()
        ->and($this->chain->wanted($this->keyframes[1], $old + 1))->toBeTrue();

    $this->chain->failed($this->keyframes[1], $old, 'Failed.', null);

    expect($this->keyframes[1]->fresh()->status)->toBe(KeyframeStatus::DRAWING);
});

it('stops what waits for a failed keyframe, so the shot is no longer busy', function () {
    $this->keyframes->first()->forceFill(['status' => KeyframeStatus::READY])->save();
    $this->chain->draw($this->keyframes->skip(1)->values());
    $second = $this->keyframes[1]->fresh();

    $this->chain->failed($second, $second->draw_version, 'The image could not be generated.', new RuntimeException('Provider down'));

    expect(statuses($this->shot))->toBe(['ready', 'failed', 'planned'])
        ->and($second->fresh()->render_error)->toBe('The image could not be generated. Provider down')
        ->and($this->shot->fresh()->status)->toBe(ShotStatus::KEYFRAMES_READY);

    $this->chain->redrawFrom($second->fresh());

    expect(statuses($this->shot))->toBe(['ready', 'drawing', 'waiting']);
});

it('draws every still of a montage at once, since none depends on another', function () {
    $this->shot->forceFill(['kind' => 'montage'])->save();
    $this->chain->link($this->shot);

    $this->chain->draw($this->shot->keyframes()->get());

    expect(statuses($this->shot))->toBe(['drawing', 'drawing', 'drawing']);
    Queue::assertPushed(DrawKeyframe::class, 3);
});

it('draws a keyframe again a little later when the model provider has a short hiccup, and fails it on anything else', function () {
    $this->keyframes->first()->forceFill(['status' => KeyframeStatus::READY])->save();
    $this->chain->draw($this->keyframes[1]);
    $second = $this->keyframes[1]->fresh();
    $hiccup = new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(429, [], '{"error":{"message":"Retry shortly."}}')));

    expect(DrawKeyframe::isHiccup($hiccup))->toBeTrue()
        ->and(DrawKeyframe::isHiccup(new RuntimeException('The layout model returned no image.')))->toBeFalse()
        ->and(DrawKeyframe::isHiccup(new RuntimeException('Wrapped.', 0, $hiccup)))->toBeTrue()
        ->and(DrawKeyframe::isHiccup(new RuntimeException("The Replicate model did not finish: {'detail': '/v1/flux-3-image is over capacity and temporarily shedding requests. Please retry shortly.'}")))->toBeTrue();

    $painter = Mockery::mock(App\Ai\KeyframePainter::class);
    $painter->shouldReceive('render')->once()->andThrow($hiccup);
    $job = (new DrawKeyframe($second, $second->draw_version))->withFakeQueueInteractions();

    $job->handle($painter, $this->chain);

    $job->assertReleased(20);
    expect($second->fresh()->status)->toBe(KeyframeStatus::DRAWING);

    $painter = Mockery::mock(App\Ai\KeyframePainter::class);
    $painter->shouldReceive('render')->once()->andThrow(new RuntimeException('The layout model returned no image.'));
    $job = (new DrawKeyframe($second, $second->draw_version))->withFakeQueueInteractions();

    $job->handle($painter, $this->chain);

    $job->assertFailed();
});
